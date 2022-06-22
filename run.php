<?php

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
    return !in_array($ghrepo, ['fallback']);
});

# sboyd tmp
$ghrepos = ['silverstripe/sspak'];

foreach ($ghrepos as $ghrepo) {
    $account = explode('/', $ghrepo)[0];
    $repo = explode('/', $ghrepo)[1];
    $dir = "modules/$repo";
    if (!file_exists($dir)) {
        // note, cannot use --depth=1 here, because ccs repo will be out of sync with silverstripe
        // so pr's wouldn't work
        // regardless, will get error ! [remote rejected] (shallow update not allowed)
        cmd("git clone git@github.com:$ghrepo $dir");
    }
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
    $current_branch = cmd([
        "cd $dir",
        'git rev-parse --abbrev-ref HEAD',
        "cd - > /dev/null"
    ]);
    if (strpos($current_branch, '/') !== false) {
        echo "Already on non-default branch for $ghrepo, not risking doing git things, continuing\n";
        continue;
    }
    $title = 'MNT Adding workflow files';
    $new_branch = "pulls/$current_branch/module-standards";
    cmd([
        "cd $path",
        "git checkout -b $new_branch",
        "git commit -m '$title'",
        "git remote add ccs git@github.com:creative-commoners/$repo",
        "git push ccs $new_branch",
        "cd - > /dev/null"
    ]);
    $post_body = <<<EOT
    {
        "title": "$title",
        "body": "Adding workflow files",
        "head": "creative-commoners:$new_branch",
        "base": "$current_branch"
    }
    EOT;
    // https://docs.github.com/en/rest/pulls/pulls#create-a-pull-request
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
