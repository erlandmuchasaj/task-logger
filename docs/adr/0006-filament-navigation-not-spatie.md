# ADR-0006: Use Filament navigation, do not install spatie/laravel-navigation

- Status: Proposed
- Context: SPEC §2.3 lists spatie/laravel-navigation (optional list). Packagist: latest 1.3.0, released 2024-09-20, no Laravel 13 constraint declared. Filament provides navigation groups, items and policy-based visibility.
- Decision: Do not install it. Use Filament's navigation.
- Consequences: One fewer dependency. Revisit only if a non-Filament page needs a menu.
