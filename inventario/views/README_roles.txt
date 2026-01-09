Work Orders Module with Roles (PHP)

Files included:
- defectives_list.php
- create_work_order.php
- work_orders_list.php
- view_work_order.php
- add_user_roles.sql

How roles are used:

- The users table now has a 'role' column:
    admin, supervisor, technician, inventory, qc, viewer

- Technicians:
    Only users with role = 'technician' are shown in the
    "Assigned to" dropdown when creating a Work Order.

- Updating item status:
    Only users with roles 'technician', 'supervisor', or 'admin'
    can update the status of items in view_work_order.php.

What you must do:

1) Run add_user_roles.sql in your database to add the 'role' column.

2) Set roles for each existing user, for example:
    UPDATE users SET role = 'admin' WHERE id = 1;

3) In your login/auth code (auth.php), after verifying the user,
   store the role in the session, for example:
    $_SESSION['role'] = $user_row['role'];

4) Copy these PHP files into your project (adjust the paths for includes
   if needed) and access:
    - defectives_list.php  to select devices and create work orders
    - work_orders_list.php to see all work orders
    - view_work_order.php?id=1  to manage a specific work order

If $_SESSION['role'] is not set, pages will assume 'viewer' (read-only
for view_work_order.php, and technicians list may be empty if no
users with role = 'technician' exist).
