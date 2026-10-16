#!/usr/bin/env bash
# Exports the old ONU app (zisan.me/onu, MySQL onu_management_db) for the Desk import, read-only.
# One JSON object per line: {"t": "<table>", "r": {...}}. No passwords, salaries, attendance, fiber or roles.
#   ssh zisan.me 'bash -s' < deploy/onu_export.sh > /tmp/onu-export.jsonl
#   php artisan onu:import /tmp/onu-export.jsonl --company=century-link-network --map=../deploy/onu-import/century-link-network.json --dry-run
set -euo pipefail
DB="${ONU_DB:-onu_management_db}"
mysql --default-character-set=utf8mb4 -N -B --raw "$DB" <<'SQL'
SELECT JSON_OBJECT('t','employees','r',JSON_OBJECT('id',id,'full_name',full_name,'username',username)) FROM employees;
SELECT JSON_OBJECT('t','brands','r',JSON_OBJECT('id',id,'name',name,'price',price,
  'created_at',DATE_FORMAT(created_at,'%Y-%m-%d %H:%i:%s'))) FROM brands;
SELECT JSON_OBJECT('t','stock_entries','r',JSON_OBJECT('id',id,'brand_id',brand_id,'quantity',quantity,
  'purchase_date',DATE_FORMAT(purchase_date,'%Y-%m-%d'),'created_at',DATE_FORMAT(created_at,'%Y-%m-%d %H:%i:%s'))) FROM stock_entries;
SELECT JSON_OBJECT('t','new_connections','r',JSON_OBJECT('id',id,'customer_id_code',customer_id_code,'customer_name',customer_name,
  'mobile_number',mobile_number,'address',address,'connection_date',DATE_FORMAT(connection_date,'%Y-%m-%d'),
  'connection_type',connection_type,'materials_used',materials_used,'total_price',total_price,'deposit_amount',deposit_amount,
  'due_amount',due_amount,'order_taker_id',order_taker_id,'money_with_id',money_with_id,
  'created_at',DATE_FORMAT(created_at,'%Y-%m-%d %H:%i:%s'))) FROM new_connections;
SELECT JSON_OBJECT('t','due_payments','r',JSON_OBJECT('id',id,'connection_id',connection_id,
  'payment_date',DATE_FORMAT(payment_date,'%Y-%m-%d'),'paid_amount',paid_amount,'discount_amount',discount_amount,
  'collected_by_id',collected_by_id,'created_at',DATE_FORMAT(created_at,'%Y-%m-%d %H:%i:%s'))) FROM due_payments;
SELECT JSON_OBJECT('t','onu_assignments','r',JSON_OBJECT('id',id,'assignment_date',DATE_FORMAT(assignment_date,'%Y-%m-%d %H:%i:%s'),
  'customer_id',customer_id,'mac_address',mac_address,'brand_name',brand_name,'purpose',purpose,'assigned_to',assigned_to,
  'created_by',created_by,'created_at',DATE_FORMAT(created_at,'%Y-%m-%d %H:%i:%s'))) FROM onu_assignments;
SELECT JSON_OBJECT('t','products','r',JSON_OBJECT('id',id,'name',name,'company',company,'current_stock',current_stock,
  'created_at',DATE_FORMAT(created_at,'%Y-%m-%d %H:%i:%s'))) FROM products;
SELECT JSON_OBJECT('t','product_stock_logs','r',JSON_OBJECT('id',id,'product_id',product_id,'log_date',DATE_FORMAT(log_date,'%Y-%m-%d'),
  'log_type',log_type,'quantity',quantity,'unit_price',unit_price,'total_price',total_price,'employee_id',employee_id,
  'reference_customer_id',reference_customer_id,'created_at',DATE_FORMAT(created_at,'%Y-%m-%d %H:%i:%s'))) FROM product_stock_logs;
SQL
