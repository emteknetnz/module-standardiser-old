<?php

include 'vendor/autoload.php';
include 'WorkflowCreator.php';
include 'funcs.php';

$creator = new WorkflowCreator();

$ghrepos = [];

foreach (array_values($creator->createCrons('ci')) as $_ghrepos) {
    $ghrepos = array_merge($ghrepos, $_ghrepos);
}

$parent_issue = 'https://github.com/silverstripe/silverstripe-framework/issues/10349';
$pr_title = 'DEP Remove branch alias';
$pr_branch = 'pulls/*/remove-branch-alias';
$pr_urls = [];

$no_master = [];
$has_branch_alias = [];

foreach ($ghrepos as $i => $ghrepo) {
    $a = explode('/', $ghrepo);
    if (count($a) != 2) {
        continue;
    }
    $account = $a[0];
    $repo = $a[1];
    $dir = "modules/$repo";
    # see if dir already exists
    if (file_exists($dir)) {
        echo "Directory for $ghrepo already exists, continuing\n";
    } else {
        cmd("git clone git@github.com:$ghrepo $dir");
    }
    $current_branch = cmd([
        "cd $dir",
        'git checkout master > /dev/null',
        'git rev-parse --abbrev-ref HEAD',
        "cd - > /dev/null"
    ]);
    if ($current_branch != 'master') {
        $current_branch = cmd([
            "cd $dir",
            'git checkout main > /dev/null',
            'git rev-parse --abbrev-ref HEAD',
            "cd - > /dev/null"
        ]);
        if ($current_branch != 'main') {
            $no_master[] = $ghrepo;
            continue;
        }
    }
    $json = json_decode(file_get_contents("modules/$repo/composer.json"));
    if (!isset($json->extra->{'branch-alias'})) {
        continue;
    }
    $has_branch_alias[] = $ghrepo;
    # create new branch from the current branch
    $new_branch = str_replace('*', $current_branch, $pr_branch);
    cmd([
        "cd $dir",
        "git checkout -b $new_branch",
        "cd - > /dev/null"
    ]);
    # update composer.json
    unset($json->extra->{'branch-alias'});
    file_put_contents("modules/$repo/composer.json", json_encode($json, JSON_PRETTY_PRINT + JSON_UNESCAPED_SLASHES) . "\n");
    # git
    cmd([
        "cd $dir",
        "git add composer.json",
        "git commit -m '$pr_title'",
        "git remote add ccs git@github.com:creative-commoners/$repo",
        "git push ccs $new_branch",
        "cd - > /dev/null"
    ]);
    # create pr
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
        print_pr_urls($pr_urls);
        die;
    }
    if ($result) {
        $pr_urls[] = json_decode($result)->url;
    }
}

print_r($pr_urls);
