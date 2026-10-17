<?php

declare(strict_types=1);

/**
 * Sembrador de datos de demostración (T-21).
 *
 * Usa EXCLUSIVAMENTE la API REST: nunca toca la base con SQL (data-model.md §9).
 * Siembra 1 bibliotecario, 40 libros, 10 socios y 15 préstamos, 3 de ellos vencidos.
 *
 * Variables de entorno:
 *   API_URL                      (opcional) por defecto http://localhost:8000/api/v1
 *   ADMIN_USERNAME, ADMIN_PASSWORD   (obligatorias) las mismas con que arrancó la API
 *   SEED_LIBRARIAN_PASSWORD      (opcional) si falta se genera una al azar y se imprime una vez
 *
 * Los préstamos vencidos se registran con la cabecera X-Simulated-Time (hace 20 días). La API solo
 * la acepta si arrancó con ALLOW_SIMULATED_TIME=true (ver manager-library-infra).
 */

const BOOK_COUNT = 40;
const MEMBER_COUNT = 10;
const OVERDUE_LOANS = 3;
const NORMAL_LOANS = 12;

$baseUrl = rtrim(getenv('API_URL') ?: 'http://localhost:8000/api/v1', '/');
$adminUser = getenv('ADMIN_USERNAME');
$adminPass = getenv('ADMIN_PASSWORD');

if (! $adminUser || ! $adminPass) {
    fwrite(STDERR, "Define ADMIN_USERNAME y ADMIN_PASSWORD en el entorno (no se guardan en el código).\n");
    exit(1);
}

/** @return array{status:int, data:mixed} */
function call(string $method, string $url, ?array $body = null, ?string $token = null, array $extraHeaders = []): array
{
    $curl = curl_init($url);
    $headers = ['Content-Type: application/json', 'Accept: application/json', ...$extraHeaders];
    if ($token !== null) {
        $headers[] = "Authorization: Bearer {$token}";
    }
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30,
    ]);
    if ($body !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
    }
    $raw = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    return ['status' => $status, 'data' => is_string($raw) ? json_decode($raw, true) : null];
}

function fail(string $message): never
{
    fwrite(STDERR, "ERROR: {$message}\n");
    exit(1);
}

function expectStatus(array $response, int $expected, string $what): array
{
    if ($response['status'] !== $expected) {
        $code = $response['data']['code'] ?? 'sin código';
        fail("{$what} respondió {$response['status']} ({$code}), se esperaba {$expected}.");
    }

    return $response['data'];
}

echo "=== Sembrador de Manager Library ({$baseUrl}) ===\n";

// 1. Sesión de administrador
$login = expectStatus(call('POST', "{$baseUrl}/auth/login", ['username' => $adminUser, 'password' => $adminPass]), 200, 'El inicio de sesión');
$token = $login['access_token'];
echo "1. Sesión iniciada como {$login['role']}.\n";

// 2. La base debe estar vacía de datos de demostración
$existing = expectStatus(call('GET', "{$baseUrl}/books?size=1", null, $token), 200, 'La consulta de libros');
if (($existing['total'] ?? 0) > 0) {
    fail('La base ya tiene libros. El sembrador espera una base recién creada (docker compose down -v).');
}

// 3. Categorías (las cinco vienen sembradas por la migración)
$categories = expectStatus(call('GET', "{$baseUrl}/categories", null, $token), 200, 'La consulta de categorías');
if (count($categories) !== 5) {
    fail('Se esperaban 5 categorías y hay '.count($categories).'.');
}
echo '2. '.count($categories)." categorías encontradas.\n";

// 4. Bibliotecario
$librarianPassword = getenv('SEED_LIBRARIAN_PASSWORD') ?: bin2hex(random_bytes(8));
$librarian = call('POST', "{$baseUrl}/users", ['username' => 'ana', 'password' => $librarianPassword, 'role' => 'librarian'], $token);
if ($librarian['status'] === 201) {
    echo "3. Bibliotecario «ana» creado. Contraseña: {$librarianPassword}\n";
} elseif ($librarian['status'] === 409) {
    echo "3. El bibliotecario «ana» ya existía.\n";
} else {
    fail("Crear al bibliotecario respondió {$librarian['status']}.");
}

// 5. Libros
$books = [];
for ($i = 1; $i <= BOOK_COUNT; $i++) {
    $category = $categories[($i - 1) % count($categories)];
    $created = expectStatus(call('POST', "{$baseUrl}/books", [
        'title' => "Libro de muestra {$i} ({$category['name']})",
        'author' => "Autor de muestra {$i}",
        'isbn' => sprintf('978%010d', 1000000000 + $i),
        'category_id' => $category['id'],
        'total_copies' => ($i % 5) + 2,
    ], $token), 201, "El libro {$i}");
    $books[] = $created;
}
echo '4. '.count($books)." libros creados.\n";

// 6. Socios
$members = [];
for ($i = 1; $i <= MEMBER_COUNT; $i++) {
    $members[] = expectStatus(call('POST', "{$baseUrl}/members", [
        'full_name' => "Socio de muestra {$i}",
        'document_number' => sprintf('1098%06d', $i),
    ], $token), 201, "El socio {$i}");
}
echo '5. '.count($members)." socios creados.\n";

// 7. Préstamos. Los tres vencidos van primero y a nombre de los socios 0, 1 y 2, que luego no
//    piden más (RN-3). Los demás reparten un libro por préstamo entre los socios 3 a 9, salvo dos
//    préstamos de dos libros: así nadie supera los 3 libros sin devolver (RN-2).
$bookIndex = 0;
$simulatedPast = gmdate('Y-m-d\TH:i:s\Z', time() - 20 * 86400);
$loans = [];

for ($i = 0; $i < OVERDUE_LOANS; $i++) {
    $response = call('POST', "{$baseUrl}/loans", [
        'member_id' => $members[$i]['id'],
        'items' => [['book_id' => $books[$bookIndex++]['id']]],
    ], $token, ["X-Simulated-Time: {$simulatedPast}"]);
    $loans[] = expectStatus($response, 201, 'El préstamo vencido '.($i + 1));
}

for ($j = 0; $j < NORMAL_LOANS; $j++) {
    $lines = in_array($j, [5, 6], true) ? 2 : 1;
    $items = [];
    for ($k = 0; $k < $lines; $k++) {
        $items[] = ['book_id' => $books[$bookIndex++]['id']];
    }
    $loans[] = expectStatus(call('POST', "{$baseUrl}/loans", [
        'member_id' => $members[OVERDUE_LOANS + ($j % 7)]['id'],
        'items' => $items,
    ], $token), 201, 'El préstamo '.($j + 1));
}
echo '6. '.count($loans)." préstamos registrados.\n";

// 8. Algunas devoluciones: un préstamo queda cerrado y otro, parcial
$firstNormal = $loans[OVERDUE_LOANS];
expectStatus(call('POST', "{$baseUrl}/loans/{$firstNormal['id']}/items/{$firstNormal['items'][0]['id']}/return", null, $token), 200, 'La devolución');
$partial = $loans[OVERDUE_LOANS + 5];
expectStatus(call('POST', "{$baseUrl}/loans/{$partial['id']}/items/{$partial['items'][0]['id']}/return", null, $token), 200, 'La devolución parcial');
echo "7. Devoluciones registradas.\n";

// 9. Verificación: T-21 pide 3 líneas vencidas y un reporte con filas
$overdue = expectStatus(call('GET', "{$baseUrl}/loans/overdue", null, $token), 200, 'La consulta de vencidos');
if (($overdue['total'] ?? 0) !== OVERDUE_LOANS) {
    fail('Se esperaban '.OVERDUE_LOANS.' líneas vencidas y hay '.($overdue['total'] ?? 0).'. ¿La API arrancó con ALLOW_SIMULATED_TIME=true?');
}
$report = expectStatus(call('GET', "{$baseUrl}/reports/most-borrowed?from=".gmdate('Y-m-d', time() - 30 * 86400).'&to='.gmdate('Y-m-d'), null, $token), 200, 'El reporte');
if (count($report['rows'] ?? []) === 0) {
    fail('El reporte de más prestados salió vacío.');
}

echo '8. Verificado: '.$overdue['total'].' vencidos y '.count($report['rows'])." filas en el reporte.\n";
echo "=== Sembrado completado ===\n";
