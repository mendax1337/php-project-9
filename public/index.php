<?php

require __DIR__ . '/../vendor/autoload.php';

use Slim\Factory\AppFactory;
use Slim\Views\PhpRenderer;
use Slim\Flash\Messages;
use Dotenv\Dotenv;
use Carbon\Carbon;

// Загружаем .env для локальной разработки
if (file_exists(__DIR__ . '/../.env')) {
    $dotenv = Dotenv::createImmutable(__DIR__ . '/../');
    $dotenv->load();
}

// Проверяем, что .env файл подхватывается — отладка
// var_dump(__DIR__ . '/../.env');
// var_dump(file_exists(__DIR__ . '/../.env'));
// var_dump($_ENV['DATABASE_URL'] ?? null);
// var_dump($_ENV['DB_USER'] ?? null);
// var_dump($_ENV['DB_PASSWORD'] ?? null);
// exit;

// Получаем параметры подключения из массива $_ENV
if (isset($_ENV['DATABASE_URL']) && strpos($_ENV['DATABASE_URL'], '://') !== false) {
    // Render: парсим DATABASE_URL вида postgresql://user:pass@host:port/dbname
    $url = parse_url($_ENV['DATABASE_URL']);
    $dsn = "pgsql:host={$url['host']};port={$url['port']};dbname=" . ltrim($url['path'], '/');
    $user = $url['user'];
    $password = $url['pass'];
} else {
    // Локальная разработка
    $dsn = $_ENV['DATABASE_URL'] ?? null;
    $user = $_ENV['DB_USER'] ?? null;
    $password = $_ENV['DB_PASSWORD'] ?? null;
}

if (!$dsn || !$user || !$password) {
    throw new \RuntimeException('Database connection settings are not set in environment variables');
}

$pdo = new PDO($dsn, $user, $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$app = AppFactory::create();
$renderer = new PhpRenderer(__DIR__ . '/../templates');

session_start();
$flash = new Messages();

// Главная страница (GET /)
$app->get('/', function ($request, $response) use ($renderer, $flash) {
    return $renderer->render($response, 'main.phtml', [
        'flash' => $_SESSION['slimFlash'] ?? [],
    ]);
});

// Обработчик добавления URL (POST /urls)
$app->post('/urls', function ($request, $response) use ($renderer, $pdo, $flash) {
    $data = $request->getParsedBody()['url'] ?? [];
    $name = trim($data['name'] ?? '');

    // Валидация
    $errors = [];
    if (empty($name)) {
        $errors[] = 'URL не должен быть пустым';
    } elseif (mb_strlen($name) > 255) {
        $errors[] = 'URL не должен превышать 255 символов';
    } elseif (!filter_var($name, FILTER_VALIDATE_URL)) {
        $errors[] = 'Некорректный URL';
    }

    if ($errors) {
        $message = $errors[0] ?? 'Ошибка валидации';
        $flash->addMessage('error', $message);
        return $renderer->render($response, 'main.phtml', [
            'url' => $name,
            'flash' => $_SESSION['slimFlash'] ?? [],
        ]);
    }

    // Нормализация: scheme + host
    $parsed = parse_url($name);
    $normalized = "{$parsed['scheme']}://{$parsed['host']}";

    // Проверка на уникальность
    $stmt = $pdo->prepare('SELECT id FROM urls WHERE name = ?');
    $stmt->execute([$normalized]);
    $exists = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($exists) {
        $flash->addMessage('success', 'Страница уже существует');
        return $response
            ->withHeader('Location', "/urls/{$exists['id']}")
            ->withStatus(302);
    }

    // Добавляем сайт в БД
    $now = (new Carbon())->toDateTimeString();
    $stmt = $pdo->prepare('INSERT INTO urls (name, created_at) VALUES (?, ?) RETURNING id');
    $stmt->execute([$normalized, $now]);
    $id = $stmt->fetchColumn();

    $flash->addMessage('success', 'Страница успешно добавлена');
    return $response
        ->withHeader('Location', "/urls/{$id}")
        ->withStatus(302);
});

// Список сайтов (GET /urls)
$app->get('/urls', function ($request, $response) use ($renderer, $pdo) {
    $stmt = $pdo->query('SELECT * FROM urls ORDER BY id DESC');
    $urls = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return $renderer->render($response, 'urls.phtml', [
        'urls' => $urls,
    ]);
});

// Страница одного сайта (GET /urls/{id})
$app->get('/urls/{id}', function ($request, $response, $args) use ($renderer, $pdo) {
    $id = (int) $args['id'];
    $stmt = $pdo->prepare('SELECT * FROM urls WHERE id = ?');
    $stmt->execute([$id]);
    $url = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$url) {
        $response->getBody()->write('Страница не найдена');
        return $response->withStatus(404);
    }

    return $renderer->render($response, 'url.phtml', [
        'url' => $url,
    ]);
});

$app->run();
