-- Run this sa HeidiSQL / phpMyAdmin (SQL tab) para ma-clear ang tanan
-- nga naka-record nga failed login attempts. Human ani, mo-balik ka sa
-- "clean slate" - ang sunod nimong pagsulay og sayop nga login mosugod
-- napud sa Stage 1 (5 minutes lockout).

USE appsys_library;

TRUNCATE TABLE login_attempts;
