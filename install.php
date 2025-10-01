#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Script de instalação para preparar o projeto após clonagem/uso via Laravel Installer.
 *
 * Passos:
 * - Cria .env a partir de .env.example (se necessário)
 * - composer install
 * - php artisan key:generate (se necessário)
 * - php artisan storage:link
 * - php artisan migrate --force
 * - php artisan db:seed --force (se existir DatabaseSeeder)
 * - npm ci|install && npm run build (se existir package.json)
 */
function run(string $command): void
{
    echo "\n> {$command}\n";

    $exitCode = 0;
    passthru($command, $exitCode);

    if ($exitCode !== 0) {
        echo "\nComando falhou com código {$exitCode}. Abortando.\n";
        exit($exitCode);
    }
}

$basePath = __DIR__;

/**
 * Verifica se um comando está disponível no PATH atual.
 */
function commandExists(string $command): bool
{
    $which = shell_exec(sprintf('command -v %s 2>/dev/null', escapeshellarg($command)));

    return is_string($which) && trim($which) !== '';
}

/**
 * Detecta execução dentro de um container Docker (ex.: Sail).
 */
function isRunningInDocker(): bool
{
    if (file_exists('/.dockerenv')) {
        return true;
    }

    $cgroupPath = '/proc/1/cgroup';
    if (is_file($cgroupPath)) {
        $cgroup = (string) file_get_contents($cgroupPath);

        return str_contains($cgroup, 'docker') || str_contains($cgroup, 'kubepods');
    }

    return false;
}

/**
 * Verifica se o usuário atual está no grupo docker.
 */
function isUserInDockerGroup(): bool
{
    $groups = shell_exec('groups');
    if (! is_string($groups)) {
        return false;
    }

    return str_contains($groups, 'docker');
}

/**
 * Configura o grupo Docker para o usuário atual.
 */
function setupDockerGroup(): void
{
    if (isUserInDockerGroup()) {
        echo "Usuário já está no grupo docker.\n";

        return;
    }

    echo "Configurando grupo Docker...\n";

    // Verifica se o grupo docker existe
    $groupExists = shell_exec('getent group docker 2>/dev/null');
    if (! $groupExists) {
        echo "Criando grupo docker...\n";
        run('sudo groupadd docker');
    } else {
        echo "Grupo docker já existe.\n";
    }

    // Adiciona usuário ao grupo docker
    echo "Adicionando usuário ao grupo docker...\n";
    run('sudo usermod -aG docker $USER');

    echo "Configuração do grupo Docker concluída.\n";
    echo "IMPORTANTE: Você precisa fazer logout e login novamente (ou reiniciar) para que as mudanças tenham efeito.\n";
    echo "Alternativamente, execute: newgrp docker\n";
}

/**
 * Verifica se devemos usar o Sail (fora do container e com vendor/bin/sail presente).
 */
function shouldUseSail(string $basePath): bool
{
    return ! isRunningInDocker() && file_exists($basePath.'/vendor/bin/sail');
}

/**
 * Executa comandos Composer dentro ou fora do Sail conforme o ambiente.
 */
function runComposer(string $args, string $basePath): void
{
    if (shouldUseSail($basePath)) {
        run("./vendor/bin/sail composer {$args}");

        return;
    }

    if (! commandExists('composer')) {
        echo "Composer não encontrado. Execute via Sail: ./vendor/bin/sail install.php\n";
        exit(1);
    }

    run("composer {$args}");
}

/**
 * Executa comandos Artisan dentro ou fora do Sail conforme o ambiente.
 */
function runArtisan(string $args, string $basePath): void
{
    if (shouldUseSail($basePath)) {
        run("./vendor/bin/sail artisan {$args}");

        return;
    }

    if (! commandExists('php')) {
        echo "PHP não encontrado. Execute via Sail: ./vendor/bin/sail install.php\n";
        exit(1);
    }

    run("php artisan {$args}");
}

/**
 * Executa comandos NPM dentro ou fora do Sail conforme o ambiente.
 */
function runNpm(string $args, string $basePath): void
{
    if (shouldUseSail($basePath)) {
        run("./vendor/bin/sail npm {$args}");

        return;
    }

    if (! commandExists('npm')) {
        echo "npm não encontrado. Rode no Sail: ./vendor/bin/sail npm {$args}\n";

        return; // Não interrompe toda a instalação; apenas pula front-end
    }

    run("npm {$args}");
}

// Configuração do Docker (apenas se não estiver rodando dentro do container)
if (! isRunningInDocker()) {
    setupDockerGroup();
}

// .env
$envPath = $basePath.'/.env';
$envExamplePath = $basePath.'/.env.example';

if (! file_exists($envPath) && file_exists($envExamplePath)) {
    if (! copy($envExamplePath, $envPath)) {
        echo ".env não pôde ser criado a partir de .env.example\n";
        exit(1);
    }

    echo "Arquivo .env criado a partir de .env.example\n";
}

// Composer (sempre executar para garantir dependências atualizadas)
runComposer('install --no-interaction --prefer-dist', $basePath);

// APP_KEY
$envContent = is_file($envPath) ? (string) file_get_contents($envPath) : '';
$hasKey = (bool) preg_match('/^APP_KEY=.+/m', $envContent);

if (! $hasKey) {
    runArtisan('key:generate', $basePath);
}

// Storage link (ignora se já existir)
$publicStorage = $basePath.'/public/storage';
if (! is_link($publicStorage) && ! is_dir($publicStorage)) {
    runArtisan('storage:link', $basePath);
}

// Migrations
runArtisan('migrate --force', $basePath);

// Seeds (se existir)
if (is_file($basePath.'/database/seeders/DatabaseSeeder.php')) {
    runArtisan('db:seed --force', $basePath);
}

// Front-end
if (is_file($basePath.'/package.json')) {
    if (is_file($basePath.'/package-lock.json')) {
        runNpm('ci', $basePath);
    } else {
        runNpm('install', $basePath);
    }

    runNpm('run build', $basePath);
}

// Remove workflow de testes do Starter Kit, caso exista
$workflowPath = $basePath.'/.github/workflows/tests.yml';
if (is_file($workflowPath)) {
    @unlink($workflowPath);

    $workflowsDir = dirname($workflowPath);
    $githubDir = dirname($workflowsDir);

    if (is_dir($workflowsDir) && count(scandir($workflowsDir)) === 2) {
        @rmdir($workflowsDir);
    }

    if (is_dir($githubDir) && count(scandir($githubDir)) === 2) {
        @rmdir($githubDir);
    }
}

echo "\nInstalação concluída com sucesso.\n";
