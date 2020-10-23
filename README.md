# Laravel Foggy

This package is a Laravel wrapper for Foggy.  
Configuration of the plugin can be found at [Foggy's docs](https://github.com/worksome/foggy).

## Installation
For installation via composer

```bash
$ composer require worksome/foggy-laravel
```

## Usage
This package adds a new artisan command for running Foggy. Simply type 

````bash
$ php artisan db:dump
````

It will by default assume that the Foggy config file, will be in `foggy.json` in the root fo the project.  
A configuration file can be supplied by using `--config` argument.

The artisan command will make the db dump to `stdout`. The best way to parse this to a file is simply to pipe it.  
```bash
$ php artisan db:dump > scrubbedDump.sql
```