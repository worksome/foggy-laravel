# Laravel Foggy

This package is a Laravel wrapper for Foggy.

Configuration of the plugin can be found at [Foggy's docs](https://github.com/worksome/foggy).

## Install

Via Composer

```shell
composer require worksome/foggy-laravel
```

## Usage

This package adds a new artisan command for running Foggy. Simply type:

````shell
php artisan db:dump
````

It will by default assume that the Foggy config file, will be in `foggy.json` in the root of the project.  
A configuration file can be supplied by using `--config` argument.

The artisan command by default will make the database dump to `stdout`. To pass the output to a file, use the `--output` (`-o`) option. 

```shell
php artisan db:dump --output scrubbed-dump.sql
```

Foggy also supports specifying a custom database connection:

```shell
php artisan db:dump --connection mysql
```
