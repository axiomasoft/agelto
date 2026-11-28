SELECT json_build_object(
  'extensions', (SELECT coalesce(json_agg(x ORDER BY x.extname), '[]') FROM
    (SELECT extname, extversion, n.nspname FROM pg_extension e JOIN pg_namespace n ON n.oid=e.extnamespace) x),
  'columns', (SELECT coalesce(json_agg(x ORDER BY x.table_schema,x.table_name,x.ordinal_position), '[]') FROM
    (SELECT table_schema,table_name,column_name,ordinal_position,data_type,udt_name,is_nullable,column_default
     FROM information_schema.columns WHERE table_schema IN ('public','partman')) x),
  'constraints', (SELECT coalesce(json_agg(x ORDER BY x.schema,x.table_name,x.name), '[]') FROM
    (SELECT n.nspname AS schema,c.relname AS table_name,k.conname AS name,k.contype AS type,
            pg_get_constraintdef(k.oid) AS definition
     FROM pg_constraint k JOIN pg_class c ON c.oid=k.conrelid JOIN pg_namespace n ON n.oid=c.relnamespace
     -- PostgreSQL 18+ also stores NOT NULL as contype 'n'; nullability is already compared via columns.
     WHERE n.nspname IN ('public','partman') AND k.contype <> 'n') x),
  'indexes', (SELECT coalesce(json_agg(x ORDER BY x.schemaname,x.tablename,x.indexname), '[]') FROM
    (SELECT schemaname,tablename,indexname,indexdef FROM pg_indexes WHERE schemaname IN ('public','partman')) x),
  'partitions', (SELECT coalesce(json_agg(x ORDER BY x.parent,x.child), '[]') FROM
    (SELECT i.inhparent::regclass::text AS parent,i.inhrelid::regclass::text AS child,
            pg_get_expr(c.relpartbound,c.oid) AS bounds
     FROM pg_inherits i JOIN pg_class c ON c.oid=i.inhrelid JOIN pg_namespace n ON n.oid=c.relnamespace
     WHERE n.nspname='public') x),
  'partman', (SELECT coalesce(json_agg(row_to_json(p) ORDER BY p.parent_table), '[]') FROM partman.part_config p)
);
