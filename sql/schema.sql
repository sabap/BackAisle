-- BackAisle SQL Server schema (setup wizard). Safe to re-run.
SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'users')
CREATE TABLE users (
  id INT IDENTITY(1,1) PRIMARY KEY,
  username NVARCHAR(128) NOT NULL UNIQUE,
  password_hash NVARCHAR(255) NOT NULL,
  role NVARCHAR(16) NOT NULL,
  source NVARCHAR(32) NOT NULL DEFAULT 'local',
  display_name NVARCHAR(255) NULL,
  created_at DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME()
);
GO
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'devices')
CREATE TABLE devices (
  id INT IDENTITY(1,1) PRIMARY KEY,
  ip NVARCHAR(64) NOT NULL,
  hostname NVARCHAR(255) NULL,
  mac NVARCHAR(64) NULL,
  model NVARCHAR(128) NULL,
  serial NVARCHAR(128) NULL,
  firmware NVARCHAR(64) NULL,
  snmp_name NVARCHAR(128) NULL,
  site NVARCHAR(128) NULL,
  building NVARCHAR(128) NULL,
  idf_closet NVARCHAR(128) NULL,
  rack NVARCHAR(128) NULL,
  circuit NVARCHAR(128) NULL,
  load_notes NVARCHAR(MAX) NULL,
  install_date NVARCHAR(32) NULL,
  last_battery_replacement NVARCHAR(32) NULL,
  warranty_replace_by NVARCHAR(32) NULL,
  sensor_expected INT NOT NULL DEFAULT 1,
  sensor_present INT NULL,
  is_simulated INT NOT NULL DEFAULT 0,
  enabled INT NOT NULL DEFAULT 1,
  created_at DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME(),
  updated_at DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME(),
  group_id INT NULL,
  snmp_profile_id INT NULL,
  va_rating FLOAT NULL,
  kind NVARCHAR(32) NOT NULL DEFAULT 'ups',
  rack_id INT NULL,
  position_u INT NULL,
  u_height INT NOT NULL DEFAULT 1,
  face NVARCHAR(16) NOT NULL DEFAULT 'both',
  port_count INT NULL,
  manufacturer NVARCHAR(128) NULL,
  template_id INT NULL
);
GO
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'poll_state')
CREATE TABLE poll_state (
  device_id INT NOT NULL PRIMARY KEY,
  last_success NVARCHAR(32) NULL,
  last_attempt NVARCHAR(32) NULL,
  last_trap NVARCHAR(32) NULL,
  consecutive_failures INT NOT NULL DEFAULT 0,
  last_error NVARCHAR(MAX) NULL,
  comm_state NVARCHAR(32) NOT NULL DEFAULT 'unknown'
);
GO
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'samples')
CREATE TABLE samples (
  id INT IDENTITY(1,1) PRIMARY KEY,
  device_id INT NOT NULL,
  ts NVARCHAR(32) NOT NULL,
  output_status INT NULL,
  battery_status INT NULL,
  capacity_pct FLOAT NULL,
  runtime_min FLOAT NULL,
  load_pct FLOAT NULL,
  input_voltage FLOAT NULL,
  output_voltage FLOAT NULL,
  temp_f FLOAT NULL,
  humidity_pct FLOAT NULL,
  on_battery INT NULL,
  sensor_present INT NULL,
  replace_battery INT NULL,
  power_w FLOAT NULL
);
GO
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'ix_samples_dev_ts')
CREATE INDEX ix_samples_dev_ts ON samples(device_id, ts);
GO
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'samples_hourly')
CREATE TABLE samples_hourly (
  device_id INT NOT NULL,
  hour_ts NVARCHAR(32) NOT NULL,
  capacity_avg FLOAT NULL,
  runtime_avg FLOAT NULL,
  load_avg FLOAT NULL,
  input_voltage_avg FLOAT NULL,
  temp_f_avg FLOAT NULL,
  humidity_avg FLOAT NULL,
  power_avg FLOAT NULL,
  PRIMARY KEY (device_id, hour_ts)
);
GO
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'events')
CREATE TABLE events (
  id INT IDENTITY(1,1) PRIMARY KEY,
  device_id INT NULL,
  ts DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME(),
  severity NVARCHAR(16) NOT NULL,
  code NVARCHAR(64) NOT NULL,
  message NVARCHAR(MAX) NOT NULL,
  details NVARCHAR(MAX) NULL
);
GO
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'thresholds')
CREATE TABLE thresholds (
  id INT IDENTITY(1,1) PRIMARY KEY,
  scope NVARCHAR(32) NOT NULL,
  site NVARCHAR(128) NULL,
  idf_closet NVARCHAR(128) NULL,
  device_id INT NULL,
  on_battery_minutes INT NOT NULL DEFAULT 5,
  capacity_low INT NOT NULL DEFAULT 30,
  runtime_low_min INT NOT NULL DEFAULT 15,
  temp_high_f FLOAT NOT NULL DEFAULT 85,
  temp_low_f FLOAT NOT NULL DEFAULT 50,
  humidity_high INT NOT NULL DEFAULT 70,
  humidity_low INT NOT NULL DEFAULT 20,
  poll_fail_count INT NOT NULL DEFAULT 3
);
GO
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'alerts')
CREATE TABLE alerts (
  id INT IDENTITY(1,1) PRIMARY KEY,
  device_id INT NOT NULL,
  code NVARCHAR(64) NOT NULL,
  severity NVARCHAR(16) NOT NULL,
  status NVARCHAR(16) NOT NULL,
  message NVARCHAR(MAX) NOT NULL,
  opened_at NVARCHAR(32) NOT NULL,
  acked_at NVARCHAR(32) NULL,
  cleared_at NVARCHAR(32) NULL
);
GO
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'audit_log')
CREATE TABLE audit_log (
  id INT IDENTITY(1,1) PRIMARY KEY,
  ts DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME(),
  username NVARCHAR(128) NULL,
  action NVARCHAR(64) NOT NULL,
  entity NVARCHAR(64) NULL,
  entity_id NVARCHAR(64) NULL,
  details NVARCHAR(MAX) NULL
);
GO
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'groups')
CREATE TABLE groups (
  id INT IDENTITY(1,1) PRIMARY KEY,
  parent_id INT NULL,
  name NVARCHAR(255) NOT NULL,
  alert_hold_sec INT NOT NULL DEFAULT 180,
  notes NVARCHAR(MAX) NULL
);
GO
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'racks')
CREATE TABLE racks (
  id INT IDENTITY(1,1) PRIMARY KEY,
  group_id INT NOT NULL,
  name NVARCHAR(128) NOT NULL,
  u_height INT NOT NULL DEFAULT 42,
  sort_order INT NOT NULL DEFAULT 0,
  notes NVARCHAR(MAX) NULL,
  created_at DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME(),
  updated_at DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME()
);
GO
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'device_templates')
CREATE TABLE device_templates (
  id INT IDENTITY(1,1) PRIMARY KEY,
  manufacturer NVARCHAR(128) NULL,
  model NVARCHAR(128) NOT NULL,
  kind NVARCHAR(32) NOT NULL DEFAULT 'other',
  u_height INT NOT NULL DEFAULT 1,
  face NVARCHAR(16) NOT NULL DEFAULT 'both',
  port_count INT NULL,
  va_rating FLOAT NULL,
  watts FLOAT NULL,
  weight_kg FLOAT NULL,
  notes NVARCHAR(MAX) NULL,
  snmp_profile_id INT NULL,
  front_picture NVARCHAR(255) NULL,
  rear_picture NVARCHAR(255) NULL,
  is_active INT NOT NULL DEFAULT 1,
  created_at DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME(),
  updated_at DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME()
);
GO
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'snmp_profiles')
CREATE TABLE snmp_profiles (
  id INT IDENTITY(1,1) PRIMARY KEY,
  name NVARCHAR(128) NOT NULL,
  username NVARCHAR(128) NOT NULL,
  auth_proto NVARCHAR(16) NOT NULL DEFAULT 'SHA',
  priv_proto NVARCHAR(16) NOT NULL DEFAULT 'AES',
  web_user NVARCHAR(128) NULL,
  is_default INT NOT NULL DEFAULT 0,
  notes NVARCHAR(MAX) NULL
);
GO
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'settings')
CREATE TABLE settings (
  k NVARCHAR(128) NOT NULL PRIMARY KEY,
  v NVARCHAR(MAX) NULL
);
GO
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'ldap_role_maps')
CREATE TABLE ldap_role_maps (
  id INT IDENTITY(1,1) PRIMARY KEY,
  group_token NVARCHAR(512) NOT NULL,
  role NVARCHAR(16) NOT NULL
);
GO
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'pending_alerts')
CREATE TABLE pending_alerts (
  device_id INT NOT NULL,
  code NVARCHAR(64) NOT NULL,
  first_seen NVARCHAR(32) NOT NULL,
  mailed_at NVARCHAR(32) NULL,
  PRIMARY KEY (device_id, code)
);
GO
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'group_alerts')
CREATE TABLE group_alerts (
  id INT IDENTITY(1,1) PRIMARY KEY,
  group_id INT NOT NULL,
  code NVARCHAR(64) NOT NULL,
  status NVARCHAR(16) NOT NULL,
  message NVARCHAR(MAX) NULL,
  opened_at NVARCHAR(32) NULL
);
GO
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'config_templates')
CREATE TABLE config_templates (
  id INT IDENTITY(1,1) PRIMARY KEY,
  name NVARCHAR(128) NOT NULL,
  source_device_id INT NULL,
  source_ip NVARCHAR(64) NULL,
  path NVARCHAR(512) NOT NULL,
  pulled_at NVARCHAR(32) NOT NULL,
  notes NVARCHAR(MAX) NULL,
  is_default INT NOT NULL DEFAULT 0
);
GO
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'firmware_images')
CREATE TABLE firmware_images (
  id INT IDENTITY(1,1) PRIMARY KEY,
  version NVARCHAR(64) NOT NULL,
  fw_path NVARCHAR(512) NOT NULL,
  data_path NVARCHAR(512) NOT NULL,
  uploaded_at NVARCHAR(32) NOT NULL,
  uploaded_by NVARCHAR(128) NULL
);
GO
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'write_jobs')
CREATE TABLE write_jobs (
  id INT IDENTITY(1,1) PRIMARY KEY,
  kind NVARCHAR(32) NOT NULL,
  status NVARCHAR(32) NOT NULL,
  simulate INT NOT NULL DEFAULT 0,
  stop_on_error INT NOT NULL DEFAULT 1,
  confirm_phrase NVARCHAR(64) NULL,
  created_by NVARCHAR(128) NULL,
  created_at DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME(),
  started_at NVARCHAR(32) NULL,
  ended_at NVARCHAR(32) NULL,
  template_id INT NULL,
  firmware_id INT NULL,
  payload_json NVARCHAR(MAX) NULL,
  error NVARCHAR(MAX) NULL
);
GO
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'write_job_targets')
CREATE TABLE write_job_targets (
  id INT IDENTITY(1,1) PRIMARY KEY,
  job_id INT NOT NULL,
  device_id INT NOT NULL,
  ip NVARCHAR(64) NOT NULL,
  hostname NVARCHAR(255) NULL,
  status NVARCHAR(32) NOT NULL DEFAULT 'queued',
  step NVARCHAR(64) NULL,
  started_at NVARCHAR(32) NULL,
  ended_at NVARCHAR(32) NULL,
  error NVARCHAR(MAX) NULL,
  post_model NVARCHAR(128) NULL,
  post_firmware NVARCHAR(64) NULL,
  post_name NVARCHAR(128) NULL,
  post_location NVARCHAR(255) NULL
);
GO
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'write_job_steps')
CREATE TABLE write_job_steps (
  id INT IDENTITY(1,1) PRIMARY KEY,
  target_id INT NOT NULL,
  seq INT NOT NULL,
  name NVARCHAR(64) NOT NULL,
  status NVARCHAR(32) NOT NULL,
  detail NVARCHAR(MAX) NULL,
  ts DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME()
);
GO
IF NOT EXISTS (SELECT 1 FROM thresholds WHERE scope = 'global')
INSERT INTO thresholds (scope, on_battery_minutes, capacity_low, runtime_low_min, temp_high_f, temp_low_f, humidity_high, humidity_low, poll_fail_count)
VALUES ('global', 5, 30, 15, 85, 50, 70, 20, 3);
GO
