<?php

include 'vendor/autoload.php';
include 'WorkflowCreator.php';
include 'funcs.php';

$creator = new WorkflowCreator();

$ghrepos = [];

foreach (array_values($creator->createCrons('ci')) as $_ghrepos) {
    $ghrepos = array_merge($ghrepos, $_ghrepos);
}

$pr_links = [];

# sboyd tmp
$exclude_ghrepos = [
    'fallback',
    // prs already created
    'silverstripe/comment-notifications',
    'silverstripe/recipe-kitchen-sink',
    'silverstripe/silverstripe-installer',
    'silverstripe/silverstripe-framework',
    'silverstripe/silverstripe-reports',
    'silverstripe/silverstripe-tagfield',
    'silverstripe/silverstripe-siteconfig',
    'silverstripe/silverstripe-versioned',
    'silverstripe/silverstripe-versioned-admin',
    'silverstripe/sspak',
];
$min_i = 100;
$max_i = 110;

$pr_urls = [];

foreach ($ghrepos as $i => $ghrepo) {
    if ($i < $min_i || $i >= $max_i || in_array($ghrepo, $exclude_ghrepos)) {
        continue;
    }
    # definitions
    $account = explode('/', $ghrepo)[0];
    $repo = explode('/', $ghrepo)[1];
    $dir = "modules/$repo";
    $title = 'MNT Use GitHub Actions CI';
    # see if dir already exists
    if (file_exists($dir)) {
        echo "Directory for $ghrepo already exists, continuing\n";
        continue;
    }
    # see if PR already exists
    $ch = create_ch("https://api.github.com/repos/$account/$repo/pulls");
    $result = curl_exec($ch);
    $resp_code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if (!$result) {
        echo "Could not fetch pull-requests for $ghrepo, exiting\n";
        die;
    }
    $pr_exists = false;
    foreach (json_decode($result) as $pr) {
        if ($pr->title == $title) {
            $pr_exists = true;
            break;
        }
    }
    if ($pr_exists) {
        echo "Pull-request for $ghrepo already exists, continuing\n";
        continue;
    }
    # clone repo
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
    # get the earliest supported minor branch e.g. 4.10, when 4.11 and 4 both exist
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
        return preg_match('#^[0-9]+\.[0-9]+$#', $b);
    });
    natsort($bs);
    $bs = array_reverse($bs);
    $current_branch = $bs[1] ?? $bs[0] ?? $current_branch;
    # create new branch from the minor branch
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
    // if (file_exists("modules/$repo/.travis.yml")) {
    //     unlink("modules/$repo/.travis.yml");
    // }
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
    cmd([
        "cd $dir",
        "git commit -m '$title'",
        "git remote add ccs git@github.com:creative-commoners/$repo",
        "git push ccs $new_branch",
        "cd - > /dev/null"
    ]);
    // https://docs.github.com/en/rest/pulls/pulls#create-a-pull-request
    $url_branch = urlencode($new_branch);
    $body = implode('<br /><br />', [
        'Issue https://github.com/silverstripe/gha-ci/issues/11',
        "Workflow run https://github.com/creative-commoners/$repo/actions?query=branch%3A$url_branch"
    ]);
    $post_body = <<<EOT
    {
        "title": "$title",
        "body": "$body",
        "head": "creative-commoners:$new_branch",
        "base": "$current_branch"
    }
    EOT;
    $ch = create_ch("https://api.github.com/repos/$account/$repo/pulls", $post_body);
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
    if ($result) {
        $pr_urls[] = json_decode($result)->url;
    }
}
echo "\rPR urls:\n";
foreach ($pr_urls as $pr_url) {
    $pr_url = str_replace('api.github.com/repos', 'github.com', $pr_url);
    $pr_url = str_replace('/pulls/', '/pull/', $pr_url);
    echo "- $pr_url\n";
}
