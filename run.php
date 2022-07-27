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
];
$min_i = 0;
$max_i = 10;

$parent_issue = 'https://github.com/silverstripe/silverstripe-framework/issues/10349';
$pr_title = 'DEP CMS 5 dependencies';
$pr_branch = 'pulls/*/major-deps';

$pr_urls = [];

$use_earliest_supported_minor = false; // otherwise use default branch
$create_new_major = true;

foreach ($ghrepos as $i => $ghrepo) {
    if ($i < $min_i || $i >= $max_i || in_array($ghrepo, $exclude_ghrepos)) {
        continue;
    }
    # definitions
    $account = explode('/', $ghrepo)[0];
    $repo = explode('/', $ghrepo)[1];
    $dir = "modules/$repo";
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
        if ($pr->title == $pr_title) {
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
    if ($create_new_major && !preg_match('#^[1-9][0-9]*$#', $current_branch)) {
        echo "On a non integer branch for creating new major, nope\n";
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
    if ($use_earliest_supported_minor) {
        $current_branch = $bs[1] ?? $bs[0] ?? $current_branch;
    } else {
        // just use the default branch
    }
    # create new branch from the current branch
    $new_branch = str_replace('*', $current_branch, $pr_branch);
    cmd([
        "cd $dir",
        "git checkout $current_branch",
        "git checkout -b $new_branch",
        "cd - > /dev/null"
    ]);
    if (true) {
        // create new minors
        $composer = json_decode(file_get_contents("$dir/composer.json"));
        $require = $composer->require;
        foreach ($require as $requirement => $version) {
            // TODO: symbiote?
            if (!preg_match('#^(silverstripe)/#', $requirement)) {
                continue;
            }
            preg_match('#^\^[0-9]#', $version, $m);
            $new_major = $m[1] + 1;
            $require->{$requirement} = "^$new_major.0";
        }
        if (isset($require->{'silverstripe/recipe-cms'})) {
            $require->{'silverstripe/recipe-cms'} = '^5.0';
        } else {
            $require->{'silverstripe/framework'} = '^5.0';
        }
        file_put_contents("$dir/composer.json", json_encode($composer));
    }
    if (false) {
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
    }
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
        "git commit -m '$pr_title'",
        "git remote add ccs git@github.com:creative-commoners/$repo",
        "git push ccs $new_branch",
        "cd - > /dev/null"
    ]);
    $url_new_branch = urlencode($new_branch);
    // https://docs.github.com/en/rest/pulls/pulls#create-a-pull-request
    $body = implode('<br /><br />', [
        "Issue $parent_issue"
    ]);
    $post_body = <<<EOT
    {
        "title": "$pr_title",
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
