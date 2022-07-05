<?php

include 'WorkflowCreator.php';

$creator = new WorkflowCreator();

$ghrepos = [];
foreach (array_values($creator->createCrons('ci')) as $_ghrepos) {
    $ghrepos = array_merge($ghrepos, $_ghrepos);
}

$h = [];
$h[] = '<style>';
$h[] = 'table { border-collapse: collapse; }';
$h[] = 'td { border: 1px solid #ddd; padding: 4px; font-family: arial; font-size: 13px; }';
$h[] = '.odd td { background-color: #eee; }';
$h[] = '.even td { background-color: #dfdfdf; }';
$h[] = '</style>';
$h[] = '<table>';
$class = 'even';
foreach ($ghrepos as $ghrepo) {
    if (in_array($ghrepo, ['fallback'])) {
        continue;
    }
    // I don't know the PR branch -- should ping API to get it
    // Just assume 4.10 for now
    $ccs_repo = 'creative-commoners/' . explode('/', $ghrepo)[1];
    $pr_branch = urlencode('pulls/4.10/module-standards'); // << todo
    $pr_link = "https://github.com/$ghrepo/pulls"; // < todo
    $src = "https://github.com/$ccs_repo/actions/workflows/ci.yml/badge.svg?branch=$pr_branch";
    $href = "https://github.com/$ccs_repo/actions?query=branch%3A$pr_branch";
    $class = $class == 'even' ? 'odd' : 'even';
    $h[] = implode("\n", [
        "<tr class=\"$class\">",
            "<td><a href=\"$pr_link\">$ghrepo</a></td>",
            "<td><a href=\"$href\"><img src=\"$src\"></a></td>",
        '</tr>'
    ]);
}
$h[] = '</table>';
file_put_contents('status.html', implode("\n", $h));
