# Tests

Plain PHP, no framework, no WordPress: each file stubs the WordPress functions it needs and runs the
real plugin code. Run them from the repo root with the plugin folder as the argument:

```
php tests/smoke-render.php .            # every shortcode renders: no fatals, no stray PHP, balanced tags
php tests/test-slashes.php .            # apostrophes in team names (1.8.24) and the one-time repair
php tests/test-bpow-and-points.php .    # the BPOW score swap and point-value parsing (1.8.25)
```

All three exit non-zero on failure. They live here rather than in a scratch folder because an earlier set
was lost to temp cleanup. `build-plugin.ps1` excludes this directory from the shipped zip.
