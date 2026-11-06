-- BackAisle database health.
--
-- In SSMS, connect to the BackAisle database as a login that can create
-- procedures and SQL Agent jobs, then run this whole script once.
-- SQL Server Agent must be running. The job is "BackAisle maintenance"
-- and starts at 2:15 AM server time.
--
-- The job follows the same health work as Ola Hallengren's SQL Server
-- Maintenance Solution (https://ola.hallengren.com/): an integrity check,
-- index maintenance, and statistics updates. It does not delete rows.
-- Readings, events, alerts, and history stay until the application removes
-- them. It does not back up the database and it does not shrink it.
-- Keep the backups you already run.
--
-- Index bands match that solution. Under 5 percent fragmentation is left
-- alone. From 5 percent up to 30 percent, the index is reorganized.
-- At 30 percent or more, it is rebuilt online when the edition allows it,
-- and offline otherwise. Statistics are updated only when rows have changed.
-- Sunday's run also checks the database.
--
-- Run this script again to replace dbo.ba_maintain. An existing job and
-- its schedule are left as they are. The job calls the procedure, so the
-- next run uses the new body.

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

    DECLARE @Low int = 5;
    DECLARE @High int = 30;
    DECLARE @MinPages int = 1000;
    DECLARE @frag float;
    DECLARE @pages int;
    DECLARE @tbl nvarchar(512);
    DECLARE @idx nvarchar(258);
    DECLARE @stat nvarchar(258);
    DECLARE @cmd nvarchar(max);
    DECLARE @online bit = CASE
        WHEN CAST(SERVERPROPERTY('EngineEdition') AS int) IN (3, 5, 8) THEN 1
        ELSE 0
    END;

    IF OBJECT_ID(N'tempdb..#idx') IS NOT NULL DROP TABLE #idx;
    SELECT
        QUOTENAME(SCHEMA_NAME(o.schema_id)) + N'.' + QUOTENAME(o.name) AS tbl,
        QUOTENAME(i.name) AS idx,
        s.avg_fragmentation_in_percent AS frag,
        s.page_count AS pages
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
      AND o.is_ms_shipped = 0
      AND s.index_level = 0
      AND s.page_count >= @MinPages
      AND s.avg_fragmentation_in_percent >= @Low;

    WHILE EXISTS (SELECT 1 FROM #idx)
    BEGIN
        SELECT TOP (1) @tbl = tbl, @idx = idx, @frag = frag, @pages = pages FROM #idx;
        DELETE FROM #idx WHERE tbl = @tbl AND idx = @idx;
        IF @frag >= @High AND @online = 1
            SET @cmd = N'ALTER INDEX ' + @idx + N' ON ' + @tbl + N' REBUILD WITH (ONLINE = ON, SORT_IN_TEMPDB = ON)';
        ELSE IF @frag >= @High
            SET @cmd = N'ALTER INDEX ' + @idx + N' ON ' + @tbl + N' REBUILD WITH (SORT_IN_TEMPDB = ON)';
        ELSE
            SET @cmd = N'ALTER INDEX ' + @idx + N' ON ' + @tbl + N' REORGANIZE';
        PRINT CONCAT(@cmd, ' -- fragmentation ', CONVERT(varchar(20), @frag), ' pages ', @pages);
        BEGIN TRY
            EXEC sys.sp_executesql @cmd;
        END TRY
        BEGIN CATCH
            IF @frag >= @High AND @online = 1
            BEGIN
                SET @cmd = N'ALTER INDEX ' + @idx + N' ON ' + @tbl + N' REBUILD WITH (SORT_IN_TEMPDB = ON)';
                PRINT CONCAT(@cmd, ' -- online rebuild was not available');
                EXEC sys.sp_executesql @cmd;
            END
            ELSE
                THROW;
        END CATCH
    END

    IF OBJECT_ID(N'tempdb..#stat') IS NOT NULL DROP TABLE #stat;
    SELECT
        QUOTENAME(OBJECT_SCHEMA_NAME(s.object_id)) + N'.' + QUOTENAME(OBJECT_NAME(s.object_id)) AS tbl,
        QUOTENAME(s.name) AS stat
    INTO #stat
    FROM sys.stats AS s
    CROSS APPLY sys.dm_db_stats_properties(s.object_id, s.stats_id) AS sp
    INNER JOIN sys.objects AS o ON o.object_id = s.object_id
    WHERE o.type = N'U'
      AND o.is_ms_shipped = 0
      AND sp.modification_counter > 0;

    WHILE EXISTS (SELECT 1 FROM #stat)
    BEGIN
        SELECT TOP (1) @tbl = tbl, @stat = stat FROM #stat;
        DELETE FROM #stat WHERE tbl = @tbl AND stat = @stat;
        SET @cmd = N'UPDATE STATISTICS ' + @tbl + N' ' + @stat;
        PRINT @cmd;
        EXEC sys.sp_executesql @cmd;
    END

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
            @description = N'Checks the BackAisle database, maintains indexes, and updates statistics. Does not delete rows, back up, or shrink.',
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
