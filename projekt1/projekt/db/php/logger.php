<?php
class Logger {
    public static function log(string $msg, string $type = 'info', array $data = []): void {
        $dir  = DB_PATH . '/log';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $file = $dir . '/' . date('Y-m-d') . '.log';
        $entry = json_encode([
            'time' => date('H:i:s'), 'type' => $type, 'msg' => $msg, 'data' => $data,
        ], JSON_UNESCAPED_UNICODE) . PHP_EOL;
        file_put_contents($file, $entry, FILE_APPEND | LOCK_EX);
    }
    public static function error(string $msg, ?Throwable $e = null): void {
        $data = $e ? ['file' => $e->getFile(), 'line' => $e->getLine(), 'err' => $e->getMessage()] : [];
        self::log($msg, 'error', $data);
    }
    /** @return list<mixed> */
    public static function getRecent(int $limit = 100): array {
        $file = DB_PATH . '/log/' . date('Y-m-d') . '.log';
        if (!file_exists($file)) return [];
        $lines   = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $decoded = array_map(fn($l) => json_decode($l, true), array_reverse(array_slice($lines, -$limit)));
        return array_values(array_filter($decoded));
    }
}
set_exception_handler(function (Throwable $e): void {
    Logger::error('Uncaught: ' . $e->getMessage(), $e);
    if (!headers_sent()) header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Interní chyba. Admin byl upozorněn.']);
});