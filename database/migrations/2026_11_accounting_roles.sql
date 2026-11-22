-- Select the existing accounting database before running this migration.
-- Keep existing account IDs, credentials, and financial data.
ALTER TABLE users ALTER COLUMN role_label SET DEFAULT 'Bookkeeper 1';

UPDATE users
SET role_label = CASE email
    WHEN 'maria.santos@cspc.edu.ph' THEN 'Head Accountant'
    WHEN 'juan.delacruz@cspc.edu.ph' THEN 'Accountant'
    WHEN 'ana.reyes@cspc.edu.ph' THEN 'Bookkeeper 1'
END
WHERE role_label = 'Authorized accountant'
  AND email IN ('maria.santos@cspc.edu.ph', 'juan.delacruz@cspc.edu.ph', 'ana.reyes@cspc.edu.ph');
