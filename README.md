# task-logger
- A task logger  for employees to log their daily work. 


## Local development seed data

`php artisan migrate:fresh --seed` creates one login per role, all with the password `12345678`
(also created in production, so change them after the first deploy, D-58): `superadmin@test.com`, `admin@test.com`, `hr@test.com`,
`assistant@test.com`, `employee@test.com`, `finance@test.com`.

When `APP_ENV=local`, `LocalDemoSeeder` also adds demo employees, customers with rates, projects,
tasks and about two months of logs. It skips itself if customers already exist. Pass `--force`
(`php artisan db:seed --class=LocalDemoSeeder --force`) to seed it in another environment.
