-- Source schema verified 2026-09-10. Contains no production data.
CREATE TABLE IF NOT EXISTS `v2_sr_attestation_records` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `device_id` varchar(64) NOT NULL,
  `platform` varchar(20) NOT NULL,
  `attestation_type` varchar(30) NOT NULL COMMENT 'play_integrity/app_attest/install_key',
  `attestation_status` varchar(20) NOT NULL DEFAULT 'pending' COMMENT 'pending/verified/failed/expired',
  `verdict_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT '原始验证结果' CHECK (json_valid(`verdict_json`)),
  `risk_flags_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT '风险标记' CHECK (json_valid(`risk_flags_json`)),
  `verified_at` int(10) unsigned DEFAULT NULL,
  `expires_at` int(10) unsigned DEFAULT NULL,
  `created_at` int(10) unsigned DEFAULT NULL,
  `updated_at` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `v2_sr_attestation_records_device_id_index` (`device_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `v2_sr_audit_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `request_id` varchar(64) DEFAULT NULL,
  `operator_id` bigint(20) unsigned DEFAULT NULL COMMENT '操作人 user_id，系统操作为 null',
  `operator_type` varchar(20) NOT NULL DEFAULT 'system' COMMENT 'admin/system/user',
  `action` varchar(60) NOT NULL COMMENT 'policy.create/grant.revoke/tier.upgrade/...',
  `target_type` varchar(40) DEFAULT NULL COMMENT 'device_profile/provider_grant/...',
  `target_id` bigint(20) unsigned DEFAULT NULL,
  `before_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`before_json`)),
  `after_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`after_json`)),
  `reason` varchar(200) DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `created_at` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `v2_sr_audit_logs_action_created_at_index` (`action`,`created_at`),
  KEY `v2_sr_audit_logs_target_type_target_id_index` (`target_type`,`target_id`),
  KEY `v2_sr_audit_logs_request_id_index` (`request_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `v2_sr_behavior_daily_agg` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `device_id` varchar(64) NOT NULL,
  `date` date NOT NULL COMMENT '聚合日期 UTC',
  `active_flag` tinyint(4) NOT NULL DEFAULT 0 COMMENT '当天是否活跃',
  `stable_session_count` smallint(5) unsigned NOT NULL DEFAULT 0,
  `stable_connected_minutes` int(10) unsigned NOT NULL DEFAULT 0,
  `effective_bytes_up` bigint(20) unsigned NOT NULL DEFAULT 0,
  `effective_bytes_down` bigint(20) unsigned NOT NULL DEFAULT 0,
  `bidirectional_active_windows` smallint(5) unsigned NOT NULL DEFAULT 0,
  `probe_only_session_count` smallint(5) unsigned NOT NULL DEFAULT 0,
  `distinct_ingress_modes_tested` smallint(5) unsigned NOT NULL DEFAULT 0,
  `distinct_destinations_daily` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '当天不重复目标站点数',
  `burstiness_ratio` decimal(4,3) NOT NULL DEFAULT 0.000,
  `probe_without_connect_ratio` decimal(4,3) NOT NULL DEFAULT 0.000,
  `created_at` int(10) unsigned DEFAULT NULL,
  `updated_at` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `v2_sr_behavior_daily_agg_user_id_device_id_date_unique` (`user_id`,`device_id`,`date`),
  KEY `v2_sr_behavior_daily_agg_user_id_index` (`user_id`),
  KEY `v2_sr_behavior_daily_agg_device_id_index` (`device_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `v2_sr_client_sessions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `session_id` varchar(64) NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `device_id` varchar(64) NOT NULL,
  `session_type` varchar(30) NOT NULL DEFAULT 'unknown' COMMENT 'stable_use/probe_only/brief',
  `network_type` varchar(10) DEFAULT NULL,
  `selected_ingress_mode` varchar(20) DEFAULT NULL,
  `started_at` int(10) unsigned DEFAULT NULL,
  `ended_at` int(10) unsigned DEFAULT NULL,
  `effective_connected_seconds` int(10) unsigned NOT NULL DEFAULT 0,
  `effective_bytes_up` bigint(20) unsigned NOT NULL DEFAULT 0,
  `effective_bytes_down` bigint(20) unsigned NOT NULL DEFAULT 0,
  `bidirectional_active_windows` smallint(5) unsigned NOT NULL DEFAULT 0,
  `connection_presence_seconds` int(10) unsigned NOT NULL DEFAULT 0,
  `burstiness_ratio` decimal(4,3) NOT NULL DEFAULT 0.000,
  `manual_batch_test_count` smallint(5) unsigned NOT NULL DEFAULT 0,
  `manual_single_test_count` smallint(5) unsigned NOT NULL DEFAULT 0,
  `probe_seconds` int(10) unsigned NOT NULL DEFAULT 0,
  `distinct_destinations_count` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '不重复目标站点数（TCP连接去重）',
  `telemetry_suspicious` tinyint(4) NOT NULL DEFAULT 0 COMMENT '0=ok 1=suspicious',
  `created_at` int(10) unsigned DEFAULT NULL,
  `updated_at` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `v2_sr_client_sessions_session_id_unique` (`session_id`),
  KEY `v2_sr_client_sessions_user_id_index` (`user_id`),
  KEY `v2_sr_client_sessions_device_id_index` (`device_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `v2_sr_device_profiles` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `device_id` varchar(64) NOT NULL COMMENT '客户端生成的设备标识',
  `install_id` varchar(64) DEFAULT NULL COMMENT '安装实例ID',
  `platform` varchar(20) NOT NULL COMMENT 'android/ios/windows/macos',
  `app_version` varchar(20) DEFAULT NULL,
  `os_version` varchar(40) DEFAULT NULL COMMENT 'OS版本 如 Android 14 / iOS 17.4',
  `device_model` varchar(60) DEFAULT NULL COMMENT '设备型号 如 HUAWEI Mate 60',
  `public_key` text DEFAULT NULL COMMENT '设备公钥 Ed25519',
  `client_capabilities` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT '客户端能力声明' CHECK (json_valid(`client_capabilities`)),
  `environment_class` varchar(30) NOT NULL DEFAULT 'L0_desktop_low',
  `trust_level` varchar(20) NOT NULL DEFAULT 'untrusted',
  `exposure_tier` varchar(30) NOT NULL DEFAULT 'intl_only',
  `exposure_tier_override` varchar(30) DEFAULT NULL COMMENT '管理员手动覆盖的暴露层，非空时优先于自动计算',
  `behavior_score` int(10) unsigned NOT NULL DEFAULT 0,
  `last_network_type` varchar(10) DEFAULT NULL COMMENT 'wifi/cellular',
  `first_network_type` varchar(10) DEFAULT NULL COMMENT '首次接入网络类型',
  `last_ip` varchar(45) DEFAULT NULL,
  `last_client_ip` varchar(45) DEFAULT NULL COMMENT '客户端直连探测到的公网IP',
  `last_request_ip` varchar(45) DEFAULT NULL COMMENT '服务端实际看到的请求IP',
  `last_ip_source` varchar(30) DEFAULT NULL COMMENT 'client_header/request_ip/invalid_client_header',
  `last_client_ip_at` int(10) unsigned DEFAULT NULL COMMENT '客户端真实IP上报时间',
  `last_active_at` int(10) unsigned DEFAULT NULL,
  `first_active_at` int(10) unsigned DEFAULT NULL COMMENT '首次活跃时间',
  `downgraded_at` int(10) unsigned DEFAULT NULL COMMENT '上次降级时间',
  `cooldown_until` int(10) unsigned DEFAULT NULL COMMENT '冷却期截止',
  `status` tinyint(4) NOT NULL DEFAULT 1 COMMENT '1=active 0=disabled',
  `created_at` int(10) unsigned DEFAULT NULL,
  `updated_at` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `v2_sr_device_profiles_device_id_unique` (`device_id`),
  KEY `v2_sr_device_profiles_user_id_status_index` (`user_id`,`status`),
  KEY `v2_sr_device_profiles_user_id_index` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `v2_sr_exposure_policies` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(80) NOT NULL COMMENT '策略名称',
  `platform` varchar(20) NOT NULL COMMENT 'android/ios/windows/macos/*',
  `network_type` varchar(10) NOT NULL DEFAULT '*' COMMENT 'wifi/cellular/*',
  `min_trust_level` varchar(20) NOT NULL DEFAULT 'untrusted',
  `exposure_tier` varchar(30) NOT NULL,
  `default_ingress_mode` varchar(20) NOT NULL DEFAULT 'intl',
  `probe_policy_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`probe_policy_json`)),
  `priority` smallint(5) unsigned NOT NULL DEFAULT 100 COMMENT '越小优先级越高',
  `rollout_percentage` tinyint(3) unsigned NOT NULL DEFAULT 100 COMMENT '灰度百分比',
  `enabled` tinyint(4) NOT NULL DEFAULT 1,
  `created_at` int(10) unsigned DEFAULT NULL,
  `updated_at` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `v2_sr_exposure_policies_name_unique` (`name`),
  KEY `v2_sr_exposure_policies_platform_network_type_enabled_index` (`platform`,`network_type`,`enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS `v2_sr_ingress_map` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `server_type` varchar(20) NOT NULL COMMENT 'vmess/trojan/shadowsocks/vless/hysteria/tuic/anytls',
  `server_id` bigint(20) unsigned NOT NULL COMMENT '对应节点表的 id',
  `exposure_tier` varchar(30) NOT NULL COMMENT 'intl_only/public_intl/domestic_limited/domestic_sensitive',
  `ingress_host` varchar(255) NOT NULL COMMENT '该暴露层下发的入口地址',
  `ingress_port` int(10) unsigned DEFAULT NULL COMMENT '入口端口（为空则用原始端口）',
  `pool_id` bigint(20) unsigned DEFAULT NULL COMMENT '非空时引用入口池健康IP集合',
  `status` tinyint(4) NOT NULL DEFAULT 1 COMMENT '1=启用 0=禁用',
  `remark` varchar(200) DEFAULT NULL COMMENT '备注',
  `created_at` int(10) unsigned DEFAULT NULL,
  `updated_at` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_server_tier` (`server_type`,`server_id`,`exposure_tier`),
  KEY `v2_sr_ingress_map_server_type_server_id_index` (`server_type`,`server_id`),
  KEY `v2_sr_ingress_map_exposure_tier_index` (`exposure_tier`),
  KEY `v2_sr_ingress_map_pool_id_index` (`pool_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `v2_sr_ingress_pool` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(80) NOT NULL COMMENT '入口名称，如 成都BGP1G / HUANG抗通报',
  `host` varchar(255) NOT NULL COMMENT '入口 IP 或域名',
  `port` int(10) unsigned DEFAULT NULL COMMENT '入口端口（为空则引用方保留节点原始端口）',
  `remark` varchar(200) DEFAULT NULL COMMENT '备注',
  `status` tinyint(4) NOT NULL DEFAULT 1 COMMENT '1=启用 0=禁用',
  `created_at` int(10) unsigned DEFAULT NULL,
  `updated_at` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_pool_name` (`name`),
  KEY `v2_sr_ingress_pool_status_index` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS `v2_sr_provider_grants` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `grant_id` varchar(64) NOT NULL COMMENT '授权ID',
  `user_id` bigint(20) unsigned NOT NULL,
  `device_id` varchar(64) NOT NULL,
  `exposure_tier` varchar(30) NOT NULL,
  `provider_package_id` bigint(20) unsigned DEFAULT NULL,
  `max_fetch_count` tinyint(3) unsigned NOT NULL DEFAULT 2,
  `fetched_count` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `last_fetch_response_hash` varchar(64) DEFAULT NULL COMMENT '幂等重试校验',
  `last_fetched_at` int(10) unsigned DEFAULT NULL,
  `expires_at` int(10) unsigned NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'active' COMMENT 'active/used/expired/revoked',
  `created_at` int(10) unsigned DEFAULT NULL,
  `updated_at` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `v2_sr_provider_grants_grant_id_unique` (`grant_id`),
  KEY `v2_sr_provider_grants_user_id_device_id_status_index` (`user_id`,`device_id`,`status`),
  KEY `v2_sr_provider_grants_user_id_index` (`user_id`),
  KEY `v2_sr_provider_grants_device_id_index` (`device_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `v2_sr_provider_packages` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(80) NOT NULL COMMENT '包名称',
  `provider_type` varchar(30) NOT NULL DEFAULT 'mihomo_proxy_provider',
  `exposure_tier` varchar(30) NOT NULL COMMENT '所属暴露层',
  `payload` longtext NOT NULL COMMENT 'provider 内容',
  `payload_sha256` varchar(64) DEFAULT NULL,
  `version` varchar(40) DEFAULT NULL,
  `enabled` tinyint(4) NOT NULL DEFAULT 1,
  `created_at` int(10) unsigned DEFAULT NULL,
  `updated_at` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `v2_sr_provider_packages_exposure_tier_enabled_index` (`exposure_tier`,`enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `v2_sr_settings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `section` varchar(50) NOT NULL COMMENT '如 global_ingress / upgrade_public_intl / downgrade',
  `key_name` varchar(80) NOT NULL COMMENT '配置项名，避开 MySQL 关键字 key',
  `value_json` longtext DEFAULT NULL COMMENT 'JSON 编码后的配置值',
  `updated_at` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_section_key` (`section`,`key_name`),
  KEY `v2_sr_settings_section_index` (`section`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `v2_sr_telemetry_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `device_id` varchar(64) NOT NULL,
  `session_id` varchar(64) DEFAULT NULL,
  `event_type` varchar(50) NOT NULL COMMENT 'manual_batch_latency_test/connect_click/disconnect/...',
  `meta_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`meta_json`)),
  `occurred_at` int(10) unsigned NOT NULL,
  `created_at` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `v2_sr_telemetry_events_user_id_event_type_occurred_at_index` (`user_id`,`event_type`,`occurred_at`),
  KEY `v2_sr_telemetry_events_user_id_index` (`user_id`),
  KEY `v2_sr_telemetry_events_device_id_index` (`device_id`),
  KEY `v2_sr_telemetry_events_session_id_index` (`session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `v2_sr_trust_profiles` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `device_id` varchar(64) NOT NULL,
  `trust_level` varchar(20) NOT NULL DEFAULT 'untrusted',
  `behavior_score` int(10) unsigned NOT NULL DEFAULT 0,
  `exposure_tier` varchar(30) NOT NULL DEFAULT 'intl_only',
  `last_upgrade_reason` varchar(255) DEFAULT NULL,
  `last_downgrade_reason` varchar(100) DEFAULT NULL,
  `last_evaluated_at` int(10) unsigned DEFAULT NULL,
  `last_upgraded_at` int(10) unsigned DEFAULT NULL,
  `last_downgraded_at` int(10) unsigned DEFAULT NULL,
  `created_at` int(10) unsigned DEFAULT NULL,
  `updated_at` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `v2_sr_trust_profiles_user_id_device_id_unique` (`user_id`,`device_id`),
  KEY `v2_sr_trust_profiles_user_id_index` (`user_id`),
  KEY `v2_sr_trust_profiles_device_id_index` (`device_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `v2_user_devices` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL COMMENT '用户ID',
  `device_id` varchar(128) NOT NULL COMMENT '设备唯一标识',
  `device_name` varchar(128) DEFAULT NULL COMMENT '设备名称',
  `device_model` varchar(64) DEFAULT NULL COMMENT '设备型号',
  `os_type` varchar(32) DEFAULT NULL COMMENT '系统类型: android/ios/windows/macos/linux',
  `os_version` varchar(32) DEFAULT NULL COMMENT '系统版本',
  `app_version` varchar(32) DEFAULT NULL COMMENT 'APP版本',
  `last_active_at` int(11) DEFAULT NULL COMMENT '最后活跃时间戳',
  `last_ip` varchar(45) DEFAULT NULL COMMENT '最后登录IP',
  `is_current` tinyint(1) DEFAULT 0 COMMENT '是否为当前设备',
  `status` tinyint(1) DEFAULT 1 COMMENT '状态: 1=正常 0=已解绑',
  `created_at` int(11) NOT NULL COMMENT '创建时间戳',
  `updated_at` int(11) NOT NULL COMMENT '更新时间戳',
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_device_unique` (`user_id`,`device_id`),
  KEY `user_id_idx` (`user_id`),
  KEY `device_id_idx` (`device_id`),
  KEY `status_idx` (`status`),
  KEY `last_active_idx` (`last_active_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户绑定设备表';

CREATE TABLE IF NOT EXISTS `v2_user_connect_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `email` varchar(255) NOT NULL,
  `ip` varchar(45) NOT NULL,
  `as_number` varchar(64) DEFAULT NULL,
  `as_name` varchar(255) DEFAULT NULL,
  `country` varchar(64) DEFAULT NULL,
  `region` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE KEY `unique_user_ip` (`user_id`,`ip`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
