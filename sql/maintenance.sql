-- BackAisle nightly maintenance.
--
-- In SSMS, connect to the BackAisle database as a login that can create
-- procedures and SQL Agent jobs, then run this whole script once.
-- SQL Server Agent must be running. The job is "BackAisle maintenance"
-- and starts at 2:15 AM server time.
--
-- The job deletes old telemetry, reorganizes fragmented indexes, and runs
-- an integrity check on Sunday. It does not back up the database and it
-- does not shrink it. Keep the backups you already run.
--
-- Open and acknowledged alerts are kept. Queued and running write jobs
-- are kept. Notification claim rows are kept so a cleared notice is not
-- mailed again.
--
-- Change the day counts at the top of dbo.ba_maintain and run this script
-- again. The procedure is replaced. An existing job and its schedule are
-- left as they are.

IF DB_NAME() IN (N'master', N'model', N'msdb', N'tempdb')
BEGIN
    RAISERROR('Connect to the BackAisle database, then run this script.', 16, 1);
    SET NOEXEC ON;
END
GO

CREATE OR ALTER PROCEDURE dbo.ba_maintain
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @SampleDays int = 90;
    DECLARE @HourlyDays int = 370;
    DECLARE @EventDays int = 30;
    DECLARE @ClearedAlertDays int = 90;
    DECLARE @AuditDays int = 365;
    DECLARE @NotificationDays int = 90;
    DECLARE @WriteJobDays int = 180;
    DECLARE @Batch int = 4000;
    DECLARE @n int;
    DECLARE @rc int;
    DECLARE @cut varchar(19);
    DECLARE @when datetime2;
    DECLARE @tbl nvarchar(512);
    DECLARE @idx nvarchar(258);
    DECLARE @cmd nvarchar(max);

    IF OBJECT_ID(N'dbo.samples', N'U') IS NOT NULL
    BEGIN
        SET @cut = CONVERT(varchar(19), DATEADD(day, -@SampleDays, SYSUTCDATETIME()), 120);
        SET @n = 0;
        WHILE 1 = 1
        BEGIN
            DELETE TOP (@Batch) FROM dbo.samples WHERE ts < @cut;
            SET @rc = @@ROWCOUNT;
            IF @rc = 0 BREAK;
            SET @n += @rc;
        END
        PRINT CONCAT('samples removed ', @n, ' older than ', @cut);
    END

    IF OBJECT_ID(N'dbo.samples_hourly', N'U') IS NOT NULL
    BEGIN
        SET @cut = CONVERT(varchar(19), DATEADD(day, -@HourlyDays, SYSUTCDATETIME()), 120);
        SET @n = 0;
        WHILE 1 = 1
        BEGIN
            DELETE TOP (@Batch) FROM dbo.samples_hourly WHERE hour_ts < @cut;
            SET @rc = @@ROWCOUNT;
            IF @rc = 0 BREAK;
            SET @n += @rc;
        END
        PRINT CONCAT('samples_hourly removed ', @n, ' older than ', @cut);
    END

    IF OBJECT_ID(N'dbo.events', N'U') IS NOT NULL
    BEGIN
        SET @when = DATEADD(day, -@EventDays, SYSUTCDATETIME());
        SET @n = 0;
        WHILE 1 = 1
        BEGIN
            DELETE TOP (@Batch) FROM dbo.events WHERE ts < @when;
            SET @rc = @@ROWCOUNT;
            IF @rc = 0 BREAK;
            SET @n += @rc;
        END
        PRINT CONCAT('events removed ', @n, ' older than ', CONVERT(varchar(19), @when, 120));
        IF NOT EXISTS (
            SELECT 1 FROM sys.indexes
            WHERE name = N'ix_events_ts' AND object_id = OBJECT_ID(N'dbo.events')
        )
            CREATE INDEX ix_events_ts ON dbo.events(ts);
    END

    IF OBJECT_ID(N'dbo.alerts', N'U') IS NOT NULL
    BEGIN
        SET @when = DATEADD(day, -@ClearedAlertDays, SYSUTCDATETIME());
        SET @n = 0;
        WHILE 1 = 1
        BEGIN
            DELETE TOP (@Batch) FROM dbo.alerts
            WHERE status = N'cleared'
              AND (
                    TRY_CONVERT(datetime2, cleared_at) < @when
                    OR (cleared_at IS NULL AND TRY_CONVERT(datetime2, opened_at) < @when)
                  );
            SET @rc = @@ROWCOUNT;
            IF @rc = 0 BREAK;
            SET @n += @rc;
        END
        PRINT CONCAT('cleared alerts removed ', @n);
    END

    IF OBJECT_ID(N'dbo.audit_log', N'U') IS NOT NULL
    BEGIN
        SET @when = DATEADD(day, -@AuditDays, SYSUTCDATETIME());
        SET @n = 0;
        WHILE 1 = 1
        BEGIN
            DELETE TOP (@Batch) FROM dbo.audit_log WHERE ts < @when;
            SET @rc = @@ROWCOUNT;
            IF @rc = 0 BREAK;
            SET @n += @rc;
        END
        PRINT CONCAT('audit_log removed ', @n);
    END

    IF OBJECT_ID(N'dbo.notifications', N'U') IS NOT NULL
    BEGIN
        SET @when = DATEADD(day, -@NotificationDays, SYSUTCDATETIME());
        SET @n = 0;
        WHILE 1 = 1
        BEGIN
            DELETE TOP (@Batch) FROM dbo.notifications
            WHERE TRY_CONVERT(datetime2, created_at) < @when;
            SET @rc = @@ROWCOUNT;
            IF @rc = 0 BREAK;
            SET @n += @rc;
        END
        PRINT CONCAT('notifications removed ', @n);
    END

    IF OBJECT_ID(N'dbo.write_jobs', N'U') IS NOT NULL
    BEGIN
        SET @when = DATEADD(day, -@WriteJobDays, SYSUTCDATETIME());
        IF OBJECT_ID(N'dbo.write_job_steps', N'U') IS NOT NULL
            DELETE s
            FROM dbo.write_job_steps AS s
            INNER JOIN dbo.write_job_targets AS t ON t.id = s.target_id
            INNER JOIN dbo.write_jobs AS j ON j.id = t.job_id
            WHERE j.status IN (N'ok', N'fail', N'cancelled')
              AND j.created_at < @when;
        IF OBJECT_ID(N'dbo.write_job_targets', N'U') IS NOT NULL
            DELETE t
            FROM dbo.write_job_targets AS t
            INNER JOIN dbo.write_jobs AS j ON j.id = t.job_id
            WHERE j.status IN (N'ok', N'fail', N'cancelled')
              AND j.created_at < @when;
        DELETE FROM dbo.write_jobs
        WHERE status IN (N'ok', N'fail', N'cancelled')
          AND created_at < @when;
        SET @rc = @@ROWCOUNT;
        PRINT CONCAT('finished write jobs removed ', @rc);
    END

    IF OBJECT_ID(N'tempdb..#idx') IS NOT NULL DROP TABLE #idx;
    SELECT
        QUOTENAME(SCHEMA_NAME(o.schema_id)) + N'.' + QUOTENAME(o.name) AS tbl,
        QUOTENAME(i.name) AS idx
    INTO #idx
    FROM sys.dm_db_index_physical_stats(DB_ID(), NULL, NULL, NULL, N'LIMITED') AS s
    INNER JOIN sys.indexes AS i
        ON i.object_id = s.object_id AND i.index_id = s.index_id
    INNER JOIN sys.objects AS o ON o.object_id = s.object_id
    WHERE i.index_id > 0
      AND i.type IN (1, 2)
      AND i.is_disabled = 0
      AND i.is_hypothetical = 0
      AND o.type = N'U'
      AND s.index_level = 0
      AND s.page_count >= 128
      AND s.avg_fragmentation_in_percent >= 10;

    WHILE EXISTS (SELECT 1 FROM #idx)
    BEGIN
        SELECT TOP (1) @tbl = tbl, @idx = idx FROM #idx;
        DELETE FROM #idx WHERE tbl = @tbl AND idx = @idx;
        SET @cmd = N'ALTER INDEX ' + @idx + N' ON ' + @tbl + N' REORGANIZE';
        PRINT @cmd;
        EXEC sys.sp_executesql @cmd;
    END

    EXEC sys.sp_updatestats;

    -- 2000-01-02 was a Sunday. The check does not depend on DATEFIRST or language.
    IF DATEDIFF(day, CONVERT(date, '20000102', 112), CONVERT(date, SYSUTCDATETIME())) % 7 = 0
    BEGIN
        PRINT 'DBCC CHECKDB';
        DBCC CHECKDB (0) WITH NO_INFOMSGS;
    END
END
GO

DECLARE @db sysname = DB_NAME();
IF EXISTS (SELECT 1 FROM msdb.dbo.sysjobs WHERE name = N'BackAisle maintenance')
BEGIN
    PRINT 'dbo.ba_maintain was updated. The existing BackAisle maintenance job was left in place.';
END
ELSE
BEGIN
    BEGIN TRY
        DECLARE @jobId uniqueidentifier;
        DECLARE @scheduleId int;
        EXEC msdb.dbo.sp_add_job
            @job_name = N'BackAisle maintenance',
            @enabled = 1,
            @description = N'Purges old BackAisle rows, reorganizes indexes, and checks the database on Sunday. Does not back up or shrink.',
            @job_id = @jobId OUTPUT;
        EXEC msdb.dbo.sp_add_jobstep
            @job_id = @jobId,
            @step_name = N'ba_maintain',
            @subsystem = N'TSQL',
            @database_name = @db,
            @command = N'EXEC dbo.ba_maintain;',
            @retry_attempts = 0,
            @on_success_action = 1,
            @on_fail_action = 2;
        IF EXISTS (SELECT 1 FROM msdb.dbo.sysschedules WHERE name = N'BackAisle maintenance nightly')
            SELECT @scheduleId = schedule_id FROM msdb.dbo.sysschedules WHERE name = N'BackAisle maintenance nightly';
        ELSE
            EXEC msdb.dbo.sp_add_schedule
                @schedule_name = N'BackAisle maintenance nightly',
                @freq_type = 4,
                @freq_interval = 1,
                @freq_subday_type = 1,
                @active_start_time = 021500,
                @schedule_id = @scheduleId OUTPUT;
        EXEC msdb.dbo.sp_attach_schedule
            @job_id = @jobId,
            @schedule_id = @scheduleId;
        EXEC msdb.dbo.sp_add_jobserver
            @job_id = @jobId,
            @server_name = N'(LOCAL)';
        PRINT 'Created SQL Agent job BackAisle maintenance. It runs at 2:15 AM server time.';
    END TRY
    BEGIN CATCH
        PRINT 'dbo.ba_maintain is in place. The SQL Agent job was not created: ' + ERROR_MESSAGE();
        PRINT 'Run EXEC dbo.ba_maintain on a schedule, or run this script again as a login that can create jobs.';
    END CATCH
END
GO
SET NOEXEC OFF;
GO
