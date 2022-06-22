<?php

include 'WorkflowCreator.php';

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
    $ci_workflow = $creator->createWorkflow('ci', $ghrepo, '');
    $keepalive_workflow = $creator->createWorkflow('ci', $ghrepo, '');
    print($ci_workflow);
}
