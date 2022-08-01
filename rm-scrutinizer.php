<?php

$dir = 'modules';
foreach (scandir($dir) as $module) {
    if ($module == '.' || $module == '..') {
        continue;
    }
    $f = "$dir/$module/.scrutinizer.yml";
    if (!file_exists($f)) {
        continue;
    }
    $s = shell_exec("cd ~/Modules/module-standardiser/modules/$module && echo $(git rev-parse --abbrev-ref HEAD) && cd -");
    if (strpos($s, '/standardise-modules') === false) {
        echo "Not on correct branch for $module\n";
        die;
    }
    unlink($f);
    $s = file_get_contents("$dir/$module/.git/config");
    if (strpos($s, 'creative-commoners') === false) {
        preg_match("#(git@github\.com:.+?)\n#", $s, $m);
        $ccs = str_replace('silverstripe', 'creative-commoners', $m[1]);
        shell_exec("cd ~/Modules/module-standardiser/modules/$module && git remote add ccs $ccs && cd -");
    }
    shell_exec("cd ~/Modules/module-standardiser/modules/$module && git add . && git commit --amend --no-edit && git push --set-upstream ccs $(git rev-parse --abbrev-ref HEAD) -f && cd -");
    echo "Updated $module\n";
}
