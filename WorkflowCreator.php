<?php

use Lorisleiva\CronTranslator\CronTranslator;

class WorkflowCreator
{
    private const MON = 1;
    private const TUE = 2;
    private const WED = 3;
    private const THU = 4;
    private const FRI = 5;
    private const SAT = 6;
    private const SUN = 0;
    private const DAILY = 99;

    private array $ghrepoToCron;

    public function __construct()
    {
        $this->init();
    }

    public function createWorkflow(string $workflow, string $ghrepo, string $actionPath): string
    {
        list($account, $repo) = explode('/', $ghrepo);
        $path = implode('/', array_filter([$actionPath, 'workflows', "$workflow.yml"]));
        $str = file_get_contents($path);
        $cron = $this->getCron($workflow, $ghrepo);
        $str = str_replace('<cron>', $cron, $str);
        $str = str_replace('<cron_description>', $this->toHumanCron($cron), $str);
        $str = str_replace('<account>', $account, $str);
        return $str;
    }

    public function createCrons(string $mode): array
    {
        return [
            $this->createCron($mode, '10pm', self::SAT) => [
                // used if a ghrepo isn't defined anywhere
                'fallback'
            ],
            $this->createCron($mode, '3am', self::DAILY) => [
                'silverstripe/recipe-kitchen-sink',
            ],
            $this->createCron($mode, '4am', self::DAILY) => [
                'silverstripe/silverstripe-installer',
            ],
            $this->createCron($mode, '11pm', self::SUN) => [
                'silverstripe/silverstripe-reports',
                'silverstripe/silverstripe-siteconfig',
                'silverstripe/silverstripe-versioned',
                'silverstripe/silverstripe-versioned-admin',
            ],
            $this->createCron($mode, '12am', self::MON) => [
                'silverstripe/comment-notifications',
                'silverstripe/cwp',
                'silverstripe/cwp-agencyextensions',
                'silverstripe/cwp-core',
            ],
            $this->createCron($mode, '1am', self::MON) => [
                'silverstripe/cwp-pdfexport',
                'silverstripe/cwp-search',
                'silverstripe/cwp-starter-theme',
                'silverstripe/cwp-watea-theme',
                'silverstripe/silverstripe-simple',
            ],
            $this->createCron($mode, '2am', self::MON) => [
                'silverstripe/doorman',
                'silverstripe/silverstripe-serve',
                'silverstripe/silverstripe-graphql-devtools',
                'silverstripe/silverstripe-testsession',
                'silverstripe/webpack-config',
            ],
            $this->createCron($mode, '11pm', self::MON) => [
                'silverstripe/silverstripe-akismet',
                'silverstripe/silverstripe-auditor',
                'silverstripe/silverstripe-admin',
            ],
            $this->createCron($mode, '12am', self::TUE) => [
                'silverstripe/silverstripe-asset-admin',
                'silverstripe/silverstripe-assets',
                'silverstripe/silverstripe-blog',
            ],
            $this->createCron($mode, '1am', self::TUE) => [
                'silverstripe/silverstripe-campaign-admin',
                'silverstripe/silverstripe-ckan-registry',
                'silverstripe/silverstripe-cms',
            ],
            $this->createCron($mode, '2am', self::TUE) => [
                'silverstripe/silverstripe-config',
                'silverstripe/silverstripe-errorpage',
                'silverstripe/silverstripe-framework',
            ],
            $this->createCron($mode, '11pm', self::TUE) => [
                'silverstripe/silverstripe-graphql',
                'silverstripe/silverstripe-comments',
                'silverstripe/silverstripe-content-widget',
                'silverstripe/silverstripe-contentreview',
            ],
            $this->createCron($mode, '12am', self::WED) => [
                'silverstripe/silverstripe-crontask',
                'silverstripe/silverstripe-documentconverter',
                'silverstripe/silverstripe-elemental',
            ],
            $this->createCron($mode, '1am', self::WED) => [
                'silverstripe/silverstripe-elemental-bannerblock',
                'silverstripe/silverstripe-elemental-fileblock',
                'silverstripe/silverstripe-environmentcheck',
            ],
            $this->createCron($mode, '2am', self::WED) => [
                'silverstripe/silverstripe-externallinks',
                'silverstripe/silverstripe-fulltextsearch',
                'silverstripe/silverstripe-gridfieldqueuedexport',
            ],
            $this->createCron($mode, '11pm', self::WED) => [
                'silverstripe/silverstripe-html5',
                'silverstripe/silverstripe-hybridsessions',
                'silverstripe/silverstripe-iframe',
                'silverstripe/silverstripe-ldap',
        
            ],
            $this->createCron($mode, '12am', self::THU) => [
                'silverstripe/silverstripe-lumberjack',
                'silverstripe/silverstripe-mimevalidator',
                'silverstripe/silverstripe-postgresql',
                'silverstripe/silverstripe-realme',
            ],
            $this->createCron($mode, '1am', self::THU) => [
                'silverstripe/silverstripe-session-manager',
                'silverstripe/recipe-authoring-tools',
                'silverstripe/recipe-blog',
            ],
            $this->createCron($mode, '2am', self::THU) => [
                'silverstripe/recipe-ccl',
                'silverstripe/recipe-cms',
                'silverstripe/recipe-collaboration',
            ],
            $this->createCron($mode, '11pm', self::THU) => [
                'silverstripe/recipe-content-blocks',
                'silverstripe/recipe-core',
                'silverstripe/recipe-form-building',
                'silverstripe/recipe-reporting-tools',
        
            ],
            $this->createCron($mode, '12am', self::FRI) => [
                'silverstripe/recipe-plugin',
                'silverstripe/recipe-services',
                'silverstripe/recipe-solr-search',
            ],
            $this->createCron($mode, '1am', self::FRI) => [
                'silverstripe/silverstripe-registry',
                'silverstripe/silverstripe-restfulserver',
                'silverstripe/silverstripe-securityreport',
            ],
            $this->createCron($mode, '2am', self::FRI) => [
                'silverstripe/silverstripe-segment-field',
                'silverstripe/silverstripe-selectupload',
                'silverstripe/silverstripe-sharedraftcontent',
            ],
            $this->createCron($mode, '11pm', self::FRI) => [
                'silverstripe/silverstripe-sitewidecontent-report',
                'silverstripe/silverstripe-spamprotection',
                'silverstripe/silverstripe-spellcheck',
                'silverstripe/silverstripe-subsites',
            ],
            $this->createCron($mode, '12am', self::SAT) => [
                'silverstripe/silverstripe-tagfield',
                'silverstripe/silverstripe-taxonomy',
                'silverstripe/silverstripe-textextraction',
                'silverstripe/silverstripe-userforms',
            ],
            $this->createCron($mode, '1am', self::SAT) => [
                'silverstripe/silverstripe-widgets',
                'silverstripe/silverstripe-mfa',
                'silverstripe/silverstripe-totp-authenticator',
            ],
            $this->createCron($mode, '2am', self::SAT) => [
                'silverstripe/silverstripe-webauthn-authenticator',
                'silverstripe/silverstripe-login-forms',
                'silverstripe/silverstripe-security-extensions',
            ],
            $this->createCron($mode, '11pm', self::SAT) => [
                'silverstripe/silverstripe-upgrader',
                'silverstripe/silverstripe-versionfeed',
                'silverstripe/sspak',
                'silverstripe/vendor-plugin',
            ],
            $this->createCron($mode, '12am', self::SUN) => [
                'symbiote/silverstripe-advancedworkflow',
                'symbiote/silverstripe-gridfieldextensions',
                'symbiote/silverstripe-multivaluefield',
                'symbiote/silverstripe-queuedjobs',
            ],
            $this->createCron($mode, '1am', self::SUN) => [
                'silverstripe/cow',
                'silverstripe/eslint-config',
                'silverstripe/MinkFacebookWebDriver',
            ],
            $this->createCron($mode, '2am', self::SUN) => [
                'silverstripe/recipe-testing',
                'silverstripe/silverstripe-behat-extension',
            ],
            $this->createCron($mode, '3am', self::SUN) => [
                // retroactive update modules
                'silverstripe/silverstripe-sqlite3',
                'silverstripe/silverstripe-staticpublishqueue',
            ],
            $this->createCron($mode, '4am', self::SUN) => [
                'bringyourownideas/silverstripe-maintenance',
                'bringyourownideas/silverstripe-composer-update-checker',
                'dnadesign/silverstripe-elemental-subsites',
                'dnadesign/silverstripe-elemental-userforms',
            ],
            $this->createCron($mode, '5am', self::SUN) => [
                'silverstripe/silverstripe-event-dispatcher',
            ],
        ];
    }

    private function init(): void
    {
        $this->ghrepoToCron = [
            'ci' => [],
            'standards' => [],
            'keepalive' => []
        ];
        foreach ($this->createCrons('ci') as $cron => $ghrepos) {
            $minute = 0;
            foreach ($ghrepos as $ghrepo) {
                $this->ghrepoToCron['ci'][$ghrepo] = preg_replace('/^[0-9]+ /', "$minute ", $cron);
                $minute += 10;
            }
        }
        foreach ($this->createCrons('standards') as $cron => $ghrepos) {
            foreach ($ghrepos as $ghrepo) {
                $day = $this->ghrepoToDay($ghrepo);
                // run on the 50th minute of an hour sometime between the 1st and the 28th of each month
                $this->ghrepoToCron['standards'][$ghrepo] = preg_replace('/^0 ([0-9]+) 1 /', "55 $1 $day ", $cron);
            }
        }
        foreach ($this->createCrons('keepalive') as $cron => $ghrepos) {
            foreach ($ghrepos as $ghrepo) {
                $day = $this->ghrepoToDay($ghrepo);
                // run on the 55th minute of an hour sometime between the 1st and the 28th of each month
                $this->ghrepoToCron['keepalive'][$ghrepo] = preg_replace('/^0 ([0-9]+) 1 /', "50 $1 $day ", $cron);
            }
        }
    }

    private function createCron(string $mode, string $hourStrNZT, int $day): string
    {
        // e.g. NZST of '11pm', SAT
        // hour is passed as NZST, though needs to be converted to UTC
        list($hour, $day) = $this->nztToUtc($hourStrNZT, $day);
        if ($mode == 'ci') {
            if ($day > 50) {
                // daily
                return sprintf('0 %d * * *', $hour);
            } else {
                // normal - once per week on a particular day
                return sprintf('0 %d * * %d', $hour, $day);
            }
        } else {
            // keepalive + standards
            // run once per month, defaults to the 1st of the month 
            return sprintf('0 %d 1 * *', $hour);
        }
    }

    private function toHumanCron(string $cron): string
    {
        $str = CronTranslator::translate($cron);
        if (strpos($str, ' at ') !== false) {
            $str = "$str UTC";
        }
        return $str;
    }

    private function nztToUtc(string $hourStrNZT, int $day): array
    {
        // note this is UTC to NZST, NZDT (daylight savings time) is not considered
        $am = strpos($hourStrNZT, 'am') !== false;
        $hour = preg_replace('/[^0-9]/', '', $hourStrNZT);
        if ($am) {
            // e.g.
            // NZST 11am SAT = UTC hour 23 SAT
            // NZST 11pm SAT = UTC hour 11 SUN
            // NZST 9pm SUN = UTC hour 9 MON
            // NZST 12am SAT = UTC hour 12 SAT (special case)
            $hour += 12;
            if ($hour == 24) {
                // special case for 12am, which should be 0am. end result is it becomes 12pm utc
                $hour = 12;
            }
        } else {
            // pm
            // e.g. NZST 11pm SAT = UTC hour 11 SUN 
            $day += 1;
            if ($day == 7) {
                $day = 0;
            }
        }
        return [$hour, $day];
    }

    private function ghrepoToDay(string $ghrepo): string
    {
        // generate a "random yet predictable" day between 1-28 based on $ghrepo string
        return (preg_replace('/[^0-9]/', '', md5($ghrepo)) % 28) + 1;
    }

    private function getCron(string $workflow, $ghrepo): string
    {
        return $this->ghrepoToCron[$workflow][$ghrepo] ?? $this->ghrepoToCron[$workflow]['fallback'];
    }
}
