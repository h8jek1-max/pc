<?php
/**
 * components.php — Správa komponent knihovny Builderu.
 * Akce: list | save | delete | scan_from_platform
 */
$db   = DB::getInstance();
$akce = (string)($data['akce'] ?? 'list');

switch ($akce) {
    case 'list':
        // Automaticky naskenuje HTML struktury z render.php souborů
        $found = 0;
        foreach (glob(PC_ROOT . '/php/mod/*/api/render.php') ?: [] as $file) {
            $slug    = basename(dirname(dirname($file)));
            $content = file_get_contents($file);
            if ($content === false) continue;
            // Jednoduchá extrakce: hledáme třídy Bootstrap/PC komponent
            if (preg_match_all('/class="([^"]*\b(btn|card|input|badge|grid|form-group)[^"]*)"/', $content, $m)) {
                foreach (array_unique($m[2]) as $cls) {
                    $existing = $db->run('builder_komponenty', 'select', ['where' => ['tags_source' => "{$slug}:{$cls}"]]);
                    if (empty($existing)) {
                        $db->run('builder_komponenty', 'insert', [
                            'nazev'       => "Z modulu '{$slug}': .{$cls}",
                            'html'        => "<div class=\"{$cls}\" data-synapse=\"\"><!-- z {$slug} --></div>",
                            'tags'        => [$cls, $slug],
                            'tags_source' => "{$slug}:{$cls}",
                        ]);
                        $found++;
                    }
                }
            }
        }
        return $db->run('builder_komponenty', 'select', ['orderBy' => 'nazev']);

    case 'save':
        $id    = (string)($data['id'] ?? '');
        
        $nazev = htmlspecialchars(strip_tags(trim((string)($data['nazev'] ?? ''))), ENT_QUOTES, 'UTF-8');
        $html  = trim((string)($data['html']  ?? ''));
        $tags  = array_filter(array_map('trim', explode(',', (string)($data['tags'] ?? ''))));
        if (!$nazev || !$html) throw new Exception('Název a HTML jsou povinné.');

        $id = trim((string)($data['id'] ?? ''));
        if ($id === '') {
            return $db->run('builder_komponenty', 'insert', [
                'nazev' => $nazev, 'html' => $html, 'tags' => $tags
            ]);
        }
        return $db->run('builder_komponenty', 'update', [
            'id' => $id, 'data' => ['nazev' => $nazev, 'html' => $html, 'tags' => $tags]
        ]);

    case 'delete':
        $id = trim((string)($data['id'] ?? ''));
        if ($id === '') throw new Exception("Chybí ID komponenty ke smazání.");
        return $db->run('builder_komponenty', 'delete', ['id' => $id]);

    case 'scan_from_platform':
        // Automaticky naskenuje HTML struktury z render.php souborů
        $found = 0;
        foreach (glob(PC_ROOT . '/php/mod/*/api/render.php') ?: [] as $file) {
            $slug    = basename(dirname(dirname($file)));
            $content = file_get_contents($file);
            if ($content === false) continue;
            // Jednoduchá extrakce: hledáme třídy Bootstrap/PC komponent
            if (preg_match_all('/class="([^"]*\b(btn|card|input|badge|grid|form-group)[^"]*)"/', $content, $m)) {
                foreach (array_unique($m[2]) as $cls) {
                    $existing = $db->run('builder_komponenty', 'select', ['where' => ['tags_source' => "{$slug}:{$cls}"]]);
                    if (empty($existing)) {
                        $db->run('builder_komponenty', 'insert', [
                            'nazev'       => "Z modulu '{$slug}': .{$cls}",
                            'html'        => "<div class=\"{$cls}\" data-synapse=\"\"><!-- z {$slug} --></div>",
                            'tags'        => [$cls, $slug],
                            'tags_source' => "{$slug}:{$cls}",
                        ]);
                        $found++;
                    }
                }
            }
        }
        return ['message' => "Naskenováno. Přidáno: {$found} nových komponent."];
        
    default:
        throw new Exception("Neznámá akce components: $akce");
}