# Navicat MySQL Tunnel 脚本  

该脚本是对 Navivat for MySQL 官方 `ntunnel_mysql.php` 脚本的重写。  
  
功能改进:  
1. 兼容 PHP 8.0
2. 去掉了无用的代码
3. 添加了日志审计功能
4. 添加了服务器保护功能 (限制连接的主机和用户)
5. 添加了 MySQL 持久链接的功能
