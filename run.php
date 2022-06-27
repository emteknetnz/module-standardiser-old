<?php

include 'vendor/autoload.php';

include 'WorkflowCreator.php';

function cmd($cmds)
{
    if (!is_array($cmds)) {
        $cmds = [$cmds];
    }
    return trim(shell_exec(implode(' && ', $cmds)));
}

$github_token = cmd('composer config -g github-oauth.github.com');
$github_user = cmd('echo $(git config --list | grep user.name) | sed -e "s/user.name=//"');

$creator = new WorkflowCreator();

$ghrepos = [];

foreach (array_values($creator->createCrons('ci')) as $_ghrepos) {
    $ghrepos = array_merge($ghrepos, $_ghrepos);
}

$ghrepos = array_filter($ghrepos, function($ghrepo) {
    return !in_array($ghrepo, [
        'fallback',
        'silverstripe/sspak', // draft PR already created
        'silverstripe/silverstripe-tagfield', // demo PR already created
        'silverstripe/silverstripe-framework' // doing manually
    ]);
});

# sboyd tmp
$ghrepos = ['silverstripe/silverstripe-tagfield'];

foreach ($ghrepos as $ghrepo) {
    $account = explode('/', $ghrepo)[0];
    $repo = explode('/', $ghrepo)[1];
    $dir = "modules/$repo";

    if (file_exists($dir)) {
        "$dir already exists, too risky, existing\n";
        die;
    }
    // note, cannot use --depth=1 here, because ccs repo will be out of sync with silverstripe
    // so pr's wouldn't work
    // regardless, will get error ! [remote rejected] (shallow update not allowed)
    cmd("git clone git@github.com:$ghrepo $dir");
    $current_branch = cmd([
        "cd $dir",
        'git rev-parse --abbrev-ref HEAD',
        "cd - > /dev/null"
    ]);
    if (strpos($current_branch, '/') !== false) {
        echo "On a non-default branch for $ghrepo, not risking doing git things\n";
        die;
    }
    // get the earliest supported minor branch e.g. 4.10, when 4.11 and 4 both exist
    $branches = cmd([
        "cd $dir",
        'git branch -r',
        "cd - > /dev/null"
    ]);
    $bs = explode("\n", $branches);
    $bs = array_map(function($b) {
        return preg_replace('#^origin/#', '', trim($b));
    }, $bs);
    $bs = array_filter($bs, function($b) {
        return (string) (float) $b === $b;
    });
    $bs = array_map(function($b) {
        return (float) $b;
    }, $bs);
    sort($bs);
    $bs = array_reverse($bs);
    $current_branch = $bs[1] ?? $bs[0] ?? $current_branch;
    // create new branch from the minor branch
    $new_branch = "pulls/$current_branch/module-standards";
    cmd([
        "cd $dir",
        "git checkout $current_branch",
        "git checkout -b $new_branch",
        "cd - > /dev/null"
    ]);
    # update workflows
    $path = "$dir/.github/workflows";
    if (!file_exists($path)) {
        mkdir($path, 0775, true);
    }
    if (!file_exists("$path/ci.yml")) {
        file_put_contents("$path/ci.yml", $creator->createWorkflow('ci', $ghrepo, ''));
    }
    if (!file_exists("$path/keepalive.yml")) {
        file_put_contents("$path/keepalive.yml", $creator->createWorkflow('keepalive', $ghrepo, ''));
    }
    # update readme badges from travis to gha - it's assumed they are always present
    $fn = file_exists("modules/$repo/README.md") ? "modules/$repo/README.md" : "modules/$repo/readme.md";
    $readme = file_get_contents($fn);
    $replace = "[![CI](https://github.com/$account/$repo/actions/workflows/ci.yml/badge.svg)](https://github.com/$account/$repo/actions/workflows/ci.yml)";
    # branch defined
    $find = preg_quote("[![Build Status](https://api.travis-ci.com/$account/$repo.svg?branch=)](https://travis-ci.com/$account/$repo)");
    $find = str_replace('\\?branch\\=', '\\?branch\\=.+?', $find);
    $readme = preg_replace("#$find#", $replace, $readme);
    # branch not defined
    $find = "[![Build Status](https://api.travis-ci.com/$account/$repo.svg)](https://travis-ci.com/$account/$repo)";
    $readme = str_replace($find, $replace, $readme);
    file_put_contents($fn, $readme);
    # delete .travis
    if (file_exists("modules/$repo/.travis.yml")) {
        unlink("modules/$repo/.travis.yml");
    }

    # git
    $diff = cmd([
        "cd $dir",
        "git add .",
        "git diff --cached",
        "cd - > /dev/null"
    ]);
    if ($diff == '') {
        echo "No diff for $ghrepo, continuing\n";
        continue;
    }
    $title = 'MNT Use GitHub Actions CI';
    cmd([
        "cd $dir",
        "git commit -m '$title'",
        "git remote add ccs git@github.com:creative-commoners/$repo",
        "git push ccs $new_branch",
        "cd - > /dev/null"
    ]);
    // https://docs.github.com/en/rest/pulls/pulls#create-a-pull-request
    $post_body = <<<EOT
    {
        "title": "$title",
        "body": "Issue https://github.com/silverstripe/gha-ci/issues/11",
        "head": "creative-commoners:$new_branch",
        "base": "$current_branch"
    }
    EOT;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://api.github.com/repos/$account/$repo/pulls");
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Accept: application/vnd.github.v3+json',
        "Authorization: token $github_token",
        "User-Agent: $github_user"
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_body);
    $result = curl_exec($ch);
    $resp_code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($resp_code == 201) {
        echo "SUCCESS - created pull-request for $ghrepo\n";
    } else {
        echo "FAILURE - did not create pull-request for $ghrepo - response code was $resp_code\n";
        echo $result;
        die;
    }
}
