<?php
class Registry {
    public static function getModules(): array {
        $modules = [];
        foreach (glob(PC_ROOT . '/php/mod/*/module.php') as $file) {
            $mod  = include $file;
            $slug = basename(dirname($file));
            if (!empty($mod['db']['table'])) {
                $dbFile = DB_PATH . "/json/{$mod['db']['table']}.db";
                if (!file_exists($dbFile)) {
                    if (!is_dir(dirname($dbFile))) mkdir(dirname($dbFile), 0755, true);
                    //file_put_contents($dbFile, json_encode([]));
					file_put_contents($dbFile, '');
                }
            }
            $modules[$slug] = $mod;
            //$modules[$slug]["file"] = $file;
        }
        uasort($modules, fn($a, $b) => ($a['order'] ?? 99) <=> ($b['order'] ?? 99));
        return $modules;
    }
}