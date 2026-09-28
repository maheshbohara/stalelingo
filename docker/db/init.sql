-- Second database used by the WordPress PHPUnit integration suite.
CREATE DATABASE IF NOT EXISTS `wordpress_test`;
GRANT ALL PRIVILEGES ON `wordpress_test`.* TO 'wordpress'@'%';
FLUSH PRIVILEGES;
