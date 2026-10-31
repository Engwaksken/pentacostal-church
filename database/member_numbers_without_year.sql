-- ============================================================================
-- MEMBER NUMBERS WITHOUT THE YEAR, MEMBER NUMBER AS USERNAME
-- ============================================================================
-- 1. Member numbers lose the year: MEM-2026-0001 becomes MEM-0001 (the number part is kept).
--    New members then continue from the highest number (e.g. MEM-0838); see
--    generate_member_no() in members/process-member.php.
-- 2. Each member's login username becomes their member number, e.g. MEM-0001.
--    Members can then sign in with their member number (or their email, as before).
--
-- Run it once on this site's database (phpMyAdmin > SQL, or the mysql client).
-- Take a backup first: phpMyAdmin > Export, or
--     mysqldump -u USER -p DATABASE > before_member_numbers.sql
-- Everything runs in one transaction: if any step fails, nothing is changed.
-- Safe to run again: a second run finds nothing left to change.
-- ============================================================================

-- ---------------------------------------------------------------------------
-- 0. CHECKS (read only). Both results must be 0 before running the changes.
-- ---------------------------------------------------------------------------

-- Member numbers that would collide: two members ending up with the same MEM-####
-- (for example MEM-2025-0001 and MEM-2026-0001), or a MEM-#### that already exists.
SELECT COUNT(*) AS member_no_collisions
FROM (
    SELECT IF(member_no LIKE 'MEM-____-%', CONCAT('MEM-', SUBSTRING(member_no, 10)), member_no) AS new_no
    FROM members
    WHERE member_no LIKE 'MEM-%'
    GROUP BY new_no
    HAVING COUNT(*) > 1
) AS clashes;

-- Usernames that would collide: another login already uses the member's new number.
SELECT COUNT(*) AS username_collisions
FROM members m
JOIN users other
  ON other.username = IF(m.member_no LIKE 'MEM-____-%', CONCAT('MEM-', SUBSTRING(m.member_no, 10)), m.member_no)
 AND other.id <> m.user_id
WHERE m.user_id IS NOT NULL;

-- ---------------------------------------------------------------------------
-- 1-2. CHANGES
-- ---------------------------------------------------------------------------
START TRANSACTION;

-- 1. Member numbers: MEM-<year>-#### -> MEM-####
UPDATE members
SET member_no = CONCAT('MEM-', SUBSTRING(member_no, 10))
WHERE member_no LIKE 'MEM-____-%';

-- 2. Username = member number, for every member that has a login.
UPDATE users u
JOIN members m ON m.user_id = u.id
SET u.username = m.member_no
WHERE m.member_no <> ''
  AND u.username <> m.member_no;

COMMIT;

-- ---------------------------------------------------------------------------
-- 3. RESULTS (read only)
-- ---------------------------------------------------------------------------
SELECT
    SUM(member_no LIKE 'MEM-%' AND member_no NOT LIKE 'MEM-____-%') AS members_without_year,
    SUM(member_no LIKE 'MEM-____-%') AS members_still_with_year,
    MAX(CAST(SUBSTRING_INDEX(member_no, '-', -1) AS UNSIGNED)) AS highest_number
FROM members;

SELECT
    COUNT(*) AS member_logins,
    SUM(u.username = m.member_no) AS username_is_member_no
FROM members m
JOIN users u ON u.id = m.user_id;

