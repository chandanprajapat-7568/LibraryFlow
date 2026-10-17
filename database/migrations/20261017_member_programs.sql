-- Apply once to an existing LibraryFlow database to retain roster sections separately.
SET @libraryflow_has_section_group = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'members' AND COLUMN_NAME = 'section_group'
);
SET @libraryflow_section_group_ddl = IF(
  @libraryflow_has_section_group = 0,
  'ALTER TABLE members ADD COLUMN section_group VARCHAR(80) NULL AFTER course',
  'SELECT 1'
);
PREPARE libraryflow_section_group_statement FROM @libraryflow_section_group_ddl;
EXECUTE libraryflow_section_group_statement;
DEALLOCATE PREPARE libraryflow_section_group_statement;

UPDATE members
SET section_group = TRIM(SUBSTRING(course, LOCATE('Section ', course))),
    course = 'BCA'
WHERE section_group IS NULL
  AND course LIKE 'BCA%Semester Section%, Group%';
