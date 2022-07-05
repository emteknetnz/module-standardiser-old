# module-standardiser

Used to keep Silverstripe modules to a common standard

Use it as a one-off script run from a laptop

Originally was intended to be used as a GitHub Action in [gha-standards](https://github.com/emteknetnz/gha-standards), though there were permissions issues that prevented that solution from being adopted

## Usage

```
rm -rf modules # if there there was a previous run

php run.php
```

## Token

You'll need a github-oauth.github.com composer token, if this isn't defined you can define it with

```
composer config --global github-oauth.github.com <token>
```

You'll need to tick the "Access public repositories" checkbox in https://github.com/settings/tokens/<token>
