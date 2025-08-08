<?php

require __DIR__ . '/../vendor/autoload.php';

use Slim\Factory\AppFactory;
use Slim\Views\PhpRenderer;
use Slim\Flash\Messages;
use Dotenv\Dotenv;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use DiDom\Document;

// Подключение .env
if (file_exists(__DIR__ . '/../.env')) {
    $dotenv = Dotenv::createImmutable(__DIR__ . '/../');
    $dotenv->load();
}

// Конфиг БД
if (isset($_ENV['DATABASE_URL']) && strpos($_ENV['DATABASE_URL'], '://') !== false) {
    $url = parse_url($_ENV['DATABASE_URL']);
    $dsn = "pgsql:host={$url['host']}";
    if (isset($url['port'])) {
        $dsn .= ";port={$url['port']}";
    }
    $dsn .= ";dbname=" . ltrim($url['path'], '/');
    $user = $url['user'];
    $password = $url['pass'];
} else {
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

// Главная страница (форма)
$app->get('/', function ($request, $response) use ($renderer, $flash) {
    return $renderer->render($response, 'main.phtml', [
        'flash' => $flash->getMessages(),
    ]);
});

// Обработка формы добавления сайта
$app->post('/urls', function ($request, $response) use ($renderer, $pdo, $flash) {
    $data = $request->getParsedBody()['url'] ?? [];
    $name = trim($data['name'] ?? '');

    $errors = [];
    if (empty($name)) {
        $errors[] = 'URL не должен быть пустым';
    } elseif (mb_strlen($name) > 255) {
        $errors[] = 'URL не должен превышать 255 символов';
    } elseif (!filter_var($name, FILTER_VALIDATE_URL)) {
        $errors[] = 'Некорректный URL';
    }

    // Ошибка — показываем главную с ошибкой, НЕ редиректим
    if ($errors) {
        return $renderer->render($response->withStatus(422), 'main.phtml', [
            'url' => $name,
            'flash' => ['error' => $errors],
        ]);
    }

    // Нормализуем URL (только схема и хост)
    $parsed = parse_url($name);
    $normalized = "{$parsed['scheme']}://{$parsed['host']}";

    // Проверяем — уже есть?
    $stmt = $pdo->prepare('SELECT id FROM urls WHERE name = ?');
    $stmt->execute([$normalized]);
    $exists = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($exists) {
        $flash->addMessage('success', 'Страница уже существует');
        return $response
            ->withHeader('Location', "/urls/{$exists['id']}")
            ->withStatus(302);
    }

    // Добавляем новый
    $now = (new Carbon())->toDateTimeString();
    $stmt = $pdo->prepare('INSERT INTO urls (name, created_at) VALUES (?, ?) RETURNING id');
    $stmt->execute([$normalized, $now]);
    $id = $stmt->fetchColumn();

    $flash->addMessage('success', 'Страница успешно добавлена');
    return $response
        ->withHeader('Location', "/urls/{$id}")
        ->withStatus(302);
});

// Список сайтов
$app->get('/urls', function ($request, $response) use ($renderer, $pdo, $flash) {
    $sql = <<<SQL
SELECT
    urls.*,
    MAX(url_checks.created_at) AS last_check,
    (
        SELECT status_code
        FROM url_checks
        WHERE url_id = urls.id
        ORDER BY created_at DESC
        LIMIT 1
    ) AS last_status_code
FROM urls
LEFT JOIN url_checks ON urls.id = url_checks.url_id
GROUP BY urls.id
ORDER BY urls.id DESC
SQL;

    $stmt = $pdo->query($sql);
    $urls = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return $renderer->render($response, 'urls.phtml', [
        'urls' => $urls,
        'flash' => $flash->getMessages(),
    ]);
});

// Карточка сайта
$app->get('/urls/{id}', function ($request, $response, $args) use ($renderer, $pdo, $flash) {
    $id = (int) $args['id'];
    $stmt = $pdo->prepare('SELECT * FROM urls WHERE id = ?');
    $stmt->execute([$id]);
    $url = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$url) {
        $response->getBody()->write('Страница не найдена');
        return $response->withStatus(404);
    }

    $stmt = $pdo->prepare('SELECT * FROM url_checks WHERE url_id = ? ORDER BY id DESC');
    $stmt->execute([$id]);
    $checks = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return $renderer->render($response, 'url.phtml', [
        'url' => $url,
        'checks' => $checks,
        'flash' => $flash->getMessages(),
    ]);
});

// Проверка сайта
$app->post('/urls/{id}/checks', function ($request, $response, $args) use ($pdo, $flash) {
    $urlId = (int) $args['id'];
    $stmt = $pdo->prepare('SELECT name FROM urls WHERE id = ?');
    $stmt->execute([$urlId]);
    $urlRow = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$urlRow) {
        $flash->addMessage('error', 'Сайт не найден');
        return $response->withHeader('Location', "/urls")->withStatus(302);
    }

    $url = $urlRow['name'];
    $now = (new Carbon())->toDateTimeString();

    $client = new Client([
        'timeout'  => 10.0,
        'http_errors' => false,
        'verify' => false,
    ]);

    try {
        $resp = $client->request('GET', $url);
        $statusCode = $resp->getStatusCode();
        $body = (string) $resp->getBody();

        $document = new Document($body);

        $h1 = optional($document->first('h1'))->text();
        $title = optional($document->first('title'))->text();
        $description = optional($document->first('meta[name=description]'))->attr('content');

        $stmt = $pdo->prepare(
            'INSERT INTO url_checks (url_id, status_code, h1, title, description, created_at) 
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$urlId, $statusCode, $h1, $title, $description, $now]);

        $flash->addMessage('success', "Страница успешно проверена");
    } catch (RequestException $e) {
        $flash->addMessage('error', 'Ошибка проверки: ' . $e->getMessage());
        return $response->withHeader('Location', "/urls/{$urlId}")->withStatus(302);
    } catch (\Exception $e) {
        $flash->addMessage('error', 'Ошибка: ' . $e->getMessage());
        return $response->withHeader('Location', "/urls/{$urlId}")->withStatus(302);
    }

    return $response->withHeader('Location', "/urls/{$urlId}")->withStatus(302);
});

$app->run();
