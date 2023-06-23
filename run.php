<?php

include 'vendor/autoload.php';
include 'WorkflowCreator.php';
include 'funcs.php';

$creator = new WorkflowCreator();

$ghrepos = [];

foreach (array_values($creator->createCrons('ci')) as $_ghrepos) {
    $ghrepos = array_merge($ghrepos, $_ghrepos);
}

// modules.json comes from
// https://raw.githubusercontent.com/silverstripe/supported-modules/gh-pages/modules.json
$supported_modules = [];
$json = json_decode(file_get_contents('modules.json'));
foreach ($json as $obj) {
    $supported_modules[$obj->github] = true;
}

// get ALL silverstripe account modules for LICENSE update (including not supported)
$no_licenses = [];
$has_health_files = [];

if (true) {
    for ($i = 1; $i <= 3; $i++) {
        $url = "https://api.github.com/users/silverstripe/repos?per_page=100&page=$i";
        echo "Fetching from $url\n";
        $ch = create_ch($url);
        $result = curl_exec($ch);
        $resp_code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if (!$result) {
            echo "Could not fetch from $url, exiting\n";
            die;
        }
        foreach (json_decode($result) as $repo) {
            if ($repo->archived) {
                continue;
            }
            if ($repo->fork) {
                continue;
            }
            $contents_url = $repo->contents_url;
            $contents_url = str_replace('/{+path}', '', $contents_url);
            echo "Fetching from $contents_url\n";
            $ch = create_ch($contents_url);
            $result = curl_exec($ch);
            $resp_code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            if (!$result) {
                echo "Could not fetch from $contents_url, exiting\n";
                die;
            }
            $has_license = false;
            foreach (json_decode($result) as $file) {
                $name = strtolower($file->name);
                if ($name === 'license' || $name === 'license.md') {
                    $has_license = true;
                }
                $s = implode('|', [
                    'support',
                    'contributing',
                    'security',
                    'code\-of\-conduct',
                    'code_of_conduct',
                    'codeofconduct',
                ]);
                if (preg_match("#^($s)(\.md|\.txt|)$#", $name)) {
                    $has_health_files[$repo->full_name] = 1;
                }
            }
            if (!$has_license) {
                $no_licenses[$repo->full_name] = 1;
            }
        }
    }
    //print_r($no_licenses);
    print_r($has_health_files);
    die;
}


// $retroactive_update = [
//     'silverstripe/silverstripe-sqlite3',
//     'silverstripe/silverstripe-staticpublishqueue',
//     'bringyourownideas/silverstripe-maintenance',
//     'bringyourownideas/silverstripe-composer-update-checker',
//     'dnadesign/silverstripe-elemental-subsites',
//     'dnadesign/silverstripe-elemental-userforms',
//     'silverstripe/silverstripe-event-dispatcher',
// ];
$retroactive_update = [];

$no_cms5 = [
    'silverstripe/cwp-pdfexport',
    'silverstripe/silverstripe-akismet',
    'silverstripe/silverstripe-spellcheck',
    'silverstripe/security-extensions',
];

$skip_modules = [
    // manually created a PR
    'silverstripe/silverstripe-sqllite3'
];

# sboyd tmp
$exclude_ghrepos = [
    'fallback',
];
$min_i = 0; // 0
$max_i = 20; // 110; // 10

#$parent_issue = 'https://github.com/silverstripeltd/product-issues/issues/570';
#$pr_title = 'MNT Standardise modules';
#$pr_branch = 'pulls/*/standardise-modules';
$parent_issue = 'https://github.com/silverstripe/.github/issues/1';
$pr_title = 'MNT Update LICENSE';
$pr_branch = 'pulls/*/license';
$create_pr = false; // set to false to dry-run
$use_earliest_supported_minor = false; // otherwise use default branch
$create_new_major = false; // note - using cms5-scanner instead

if (false) {
    $ghrepos = [];
    $c = file_get_contents('no-license.txt');
    $lines = preg_split("#\n#", $c);
    foreach ($lines as $line) {
        // [silverstripe/gha-issue] => 1
        preg_match('#\[(.+?)\]#', $line, $m);
        $ghrepos[] = $m[1];
    }
}

$pr_urls = [];

foreach ($ghrepos as $i => $ghrepo) {
    if ($i < $min_i || $i >= $max_i || in_array($ghrepo, $exclude_ghrepos)) {
        continue;
    }
    if (in_array($ghrepo, $no_cms5)) {
        continue;
    }
    if (in_array($ghrepo, $skip_modules)) {
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
    $bs = array_filter($bs, function($b) use ($current_branch) {
        if (!preg_match('#^([0-9])+\.[0-9]+$#', $b, $m)) {
            return false;
        }
        return $m[1] == $current_branch;
    });
    natsort($bs);
    $bs = array_reverse($bs);
    if ($use_earliest_supported_minor) {
        $current_branch = $bs[1] ?? $bs[0] ?? $current_branch;
    } else {
        // just use the default branch
    }
    // tmp - has wrong default branch which I can't change
    if ($ghrepo == 'bringyourownideas/silverstripe-composer-update-checker') {
        $current_branch = '3.0';
    }
    # create new branch from the current branch
    $new_branch = str_replace('*', $current_branch, $pr_branch);
    cmd([
        "cd $dir",
        "git checkout $current_branch",
        "git checkout -b $new_branch",
        "cd - > /dev/null"
    ]);
    if (false) {
        $workflow_dir = "$dir/.github/workflows";
        # retroactive update
        if (in_array($ghrepo, $retroactive_update)) {
            if (file_exists("$workflow_dir/main.yml")) {
                if (preg_match('#ci\.yml#', file_get_contents("$workflow_dir/main.yml"))) {
                    unlink("$workflow_dir/main.yml");
                }
            }
            if (!file_exists($workflow_dir)) {
                mkdir($workflow_dir, 0775, true);
            }
            if (file_exists("$workflow_dir/ci.yml")) {
                unlink("$workflow_dir/ci.yml");
            }
            file_put_contents("$workflow_dir/ci.yml", $creator->createWorkflow('ci', $ghrepo, ''));
            if (!file_exists("$workflow_dir/keepalive.yml")) {
                file_put_contents("$workflow_dir/keepalive.yml", $creator->createWorkflow('keepalive', $ghrepo, ''));
            }
        }
        # standardise to README.md
        foreach (['readme.md', 'README.MD', 'README', 'readme'] as $fn) {
            if (file_exists("$dir/$fn")) {
                // rename file
                rename("$dir/$fn", "$dir/README.md");
            }
        }
        if (!file_exists("$dir/README.md")) {
            echo "$dir/README.md does not exist when it should\n";
            die;
        }
        # README.md badges
        $s = file_get_contents("$dir/README.md");
        // nuke all existing badges
        $s = preg_replace("/\n *\[\!.+?\n([#a-z0-9])/si", "\n" . '$1', $s);
        $bdgs = [];
        // add new badges
        if (file_exists("$workflow_dir/ci.yml")) {
            $bdgs[] = "[![CI](https://github.com/$account/$repo/actions/workflows/ci.yml/badge.svg)](https://github.com/$account/$repo/actions/workflows/ci.yml)";
        }
        if (array_key_exists($ghrepo, $supported_modules)) {
            $bdgs[] = "[![SilverStripe supported module](https://img.shields.io/badge/silverstripe-supported-0071C4.svg)](https://www.silverstripe.org/software/addons/silverstripe-commercially-supported-module-list/)";
        }
        if (!empty($bdgs)) {
            $bdgs[] = '';
            $badges = implode("\n", $bdgs);
            if (substr($s, 0, 1) == '#') {
                // module has heading e.g. ## Silverstripe Framework
                $pos = strpos($s, "\n");
                $s = substr($s, 0, $pos) . "\n\n" . $badges . substr($s, $pos);
            } else {
                $s = $badges . "\n" . $s;
            }
        }
        // change SilverStripe to Silverstripe, excluding things like namespaces in code examples
        $s = str_replace('SilverStripe ', 'Silverstripe ', $s);
        // remove extra newlines
        $s = str_replace("\n\n\n", "\n\n", $s);
        file_put_contents("$dir/README.md", $s);
        # change syntax in .yml files
        foreach (['ci.yml', 'keepalive.yml'] as $fn) {
            if (file_exists("$workflow_dir/$fn")) {
                $s = file_get_contents("$workflow_dir/$fn");
                $s = str_replace(
                    "startsWith(github.repository, 'silverstripe/')",
                    "github.repository_owner == 'silverstripe'",
                    $s
                );
                file_put_contents("$workflow_dir/$fn", $s);
            }
        }
        # use new update-js action
        if (file_exists("$workflow_dir/update-js-deps.yml")) {
            unlink("$workflow_dir/update-js-deps.yml");
            $contents = file_get_contents('workflows/update-js.yml');
            $contents = str_replace('<account>', $account, $contents);
            file_put_contents("$workflow_dir/update-js.yml", $contents);
        }
        # delete files
        foreach (['.travis.yml', '.codecov.yml', '.scrutinizer.yml'] as $fn) {
            if (file_exists("$dir/$fn")) {
                unlink("$dir/$fn");
            }
        }
        # phpcs
        foreach (['phpcs.xml', 'PHPCS.xml', 'PHPCS.xml.dist'] as $fn) {
            if (file_exists("$dir/$fn")) {
                rename("$dir/$fn", "$dir/phpcs.xml.dist");
            }
        }
        # phpunit
        foreach (['phpunit.xml', 'PHPUNIT.xml', 'PHPUNIT.xml.dist'] as $fn) {
            if (file_exists("$dir/$fn")) {
                rename("$dir/$fn", "$dir/phpunit.xml.dist");
            }
        }
        foreach (['phpunit.xml.dist', 'phpcs.xml.dist'] as $fn) {
            if (file_exists("$dir/$fn")) {
                $s = file_get_contents("$dir/$fn");
                $str = '<' . '?xml version="1.0" encoding="UTF-8"' . '?' . '>';
                if (strpos($s, '<' . '?xml') === false) {
                    $s = $str . "\n" . $s;
                    file_put_contents("$dir/$fn", $s);
                }
            }
        }
    }
    if (false) {
        # update workflows
        $path = "$dir/.github/workflows";
        if (!file_exists($path)) {
            mkdir($path, 0775, true);
        }
        #if (!file_exists("$path/ci.yml")) {
            file_put_contents("$path/ci.yml", $creator->createWorkflow('ci', $ghrepo, ''));
        #}
        //if (!file_exists("$path/dispatch-ci.yml")) {
            file_put_contents("$path/dispatch-ci.yml", $creator->createWorkflow('dispatch-ci', $ghrepo, ''));
        //}
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
    if (true) {
        # add LICENSE
        $license = file_get_contents(__DIR__ . '/files/LICENSE');
        file_put_contents("$dir/LICENSE", $license);
    }
    if (!$create_pr) {
        continue;
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

    // WASN'T creating PRs for some reason for LICENSE stuff. No idea why not
    if (true) continue;

    $ch = create_ch("https://api.github.com/repos/$account/$repo/pulls", $post_body);
    $result = curl_exec($ch);
    $resp_code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($resp_code == 201) {
        echo "SUCCESS - created pull-request for $ghrepo\n";
    } else {
        echo "FAILURE - did not create pull-request for $ghrepo - response code was $resp_code\n";
        echo $result;
        print_pr_urls($pr_urls);
        die;
    }
    if ($result) {
        $pr_urls[] = json_decode($result)->url;
    }
}
print_pr_urls($pr_urls);
