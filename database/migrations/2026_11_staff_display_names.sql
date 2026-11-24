-- Run after 2026_11_accounting_roles.sql. Keep the existing sign-in emails.
UPDATE cspc_accounting.users
SET full_name = 'Gabriel Mateo', initials = 'GM'
WHERE email = 'juan.delacruz@cspc.edu.ph' AND full_name = 'Juan Dela Cruz';

UPDATE cspc_accounting.users
SET full_name = 'Sofia Navarro', initials = 'SN'
WHERE email = 'ana.reyes@cspc.edu.ph' AND full_name = 'Ana Reyes';
