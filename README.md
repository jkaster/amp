# amp
Analyzer for MySQL Performance

PHP Command-line interface (CLI) for analyzing MySQL databases via recorded SQL logs

This is (currently) a command-line tool that writes analysis information to a database table.

The default configuration file is called `amp.config.php`. It's used to load the log entries, profile them against a database, and write the results to `amp.summary` by default. The output database and table name can be customized in the configuration file. This source file contains comments explaining each configuration option.

Other configuration files can be used by specifying `-c` or `-config` on the command line, followed by the name of the configuration file. For example:

```php amp.php -c amp.config.10logs.php```
