"""Reviewed response DTO shapes. Extensible objects allow additive module fields."""
S={'type':'string'}; I={'type':'integer'}; N={'type':'number'}; B={'type':'boolean'}
def nullable(s):return {'anyOf':[s,{'type':'null'}]}
def arr(s):return {'type':'array','items':s}
def mapping(s):return {'anyOf':[{'type':'object','additionalProperties':s},{'type':'array','maxItems':0,'items':{}}]}
def obj(p,required=None):return {'type':'object','properties':p,'required':list(p) if required is None else required,'additionalProperties':True}
def fields(names):
 p={}
 for item in names.split():
  k,_,t=item.partition(':');p[k]={'i':I,'b':B,'n':N,'s':S,'si':{'type':['string','integer']},'bi':{'type':['boolean','integer']}}.get(t.rstrip('?'),S)
  if t.endswith('?'):p[k]=nullable(p[k])
 return p
def dto(names):return obj(fields(names),[])
def page(item):return obj({'data':arr(item),**fields('current_page:i last_page:i per_page:i total:i from:i? to:i? first_page_url last_page_url next_page_url:s? prev_page_url:s? path'),'links':arr(dto('url:s? label active:b'))})
ID=obj({'id':I});STATUS=obj({'status':S});SAVED=obj({'saved':B});UPDATED=obj({'updated':B});MESSAGE=obj({'message':S})
USER=dto('id:i name email role tenant_id:i? active:b platform_role_id:i? restaurant_role_id:i? email_verified_at:s? created_at updated_at')
TENANT=dto('id:i name email phone:s? address:s? timezone status server_id:i? organization_id:i? is_demo:b placement_version:i cuisine:s? price_range:s? total_seats:i? description:s? website:s? logo_url:s? created_at updated_at')
SERVER=dto('id:i name host port:i region purpose database username tls_required:b provisioning_enabled:bi credentials_configured:b version:i created_at updated_at')
TICKET=dto('id:i tenant_id:i user_id:i subject priority status created_at updated_at')
ROLE=obj({**fields('id:i tenant_id:i? name version:i locked:bi'),'permissions':arr(S),'draft_permissions':arr(S)},[])
INVOICE=dto('id:i tenant_id:i order_id:i? original_id:i? kind status number:s? total_cents:i tax_cents:i issued_at:s? created_at payment_status due_at:s? settled_at:s? provider_id:s?')
ORDER=dto('id:i tenant_id:i module_code amount_cents:i currency status request_key payment_reference:s? period_start:s? period_end:s? created_at updated_at')
PRODUCT=dto('id:i module_code name description amount_cents:i? currency available:bi')
SUB=dto('id:i tenant_id:i module_code amount_cents:i state live:bi cancel_at_period_end:bi period_end:s? synced_at:s? sync_error:s?')
ENT=dto('id:i tenant_id:i module_code paid_until status created_at updated_at')
PARTY=dto('name street postal_code city country email tax_id:s? revision:i tax_rate_bps:i tax_note:s? payment_note:s?')
RES=dto('id:i table_id:i guest_name email:s? phone:s? party_size:i starts_at ends_at duration_minutes:i status notes:s? version:i request_key:s? source created_at updated_at')
WAIT=dto('id:i guest_name email:s? phone:s? party_size:i requested_at duration_minutes:i notes:s? status version:i reservation_id:i? request_key created_at updated_at')
HOURS=dto('id:i weekday:i opens closes created_at updated_at')
ROOM=dto('id:i name color outdoor:bi weather_dependent:bi location:s? note:s? icon created_at updated_at')
TABLE=dto('id:i name room_id:i capacity:i active:bi shape layout_x:i? layout_y:i? created_at updated_at')
SPECIAL=dto('id:i date closed:bi opens:s? closes:s? note:s? created_at updated_at')
RESOURCE={'anyOf':[ROOM,TABLE,HOURS,SPECIAL]}
RELEASE=dto('id:i module version category notes git_url:s? published_at:s? revision:i created_at updated_at')
EVENT=dto('id:i reservation_id:i version:i channel kind status due_at processed_at:s?')
ATTEMPT=dto('id:i event_id:i channel status created_at updated_at')
NOTIFICATION=obj({'settings':nullable(dto('id:i email_enabled:bi sms_enabled:bi reminder_minutes:i')),'ready':obj({'email':B,'sms':B}),'recent':arr(EVENT),'attempts':arr(ATTEMPT)})
WEATHER=obj({'status':S,'days':arr(dto('date rain_probability:i? precipitation:n? temperature_max:n? temperature_min:n?'))},['status','days'])
FAMILY=dto('code name scope module description')
CATALOG={'type':'object','additionalProperties':S}
ROLECAT=obj({'catalog':CATALOG,'families':arr(FAMILY),'roles':arr(ROLE)})
CONNECTION=dto('id name host port:si database username credentials_configured:b tenant_id:i? kind')
AUDIT=dto('id:i tenant_id:i? user_id:i? event action target:s? ip:s? created_at')
OPERATION=dto('id:i tenant_id:i kind status target_server_id:i? module_code:s? error_code:s? created_at updated_at')
BUILD=dto('id status run_id:si? release_tag:s? created_at')
PERMISSION=dto('code name description scope module')
MODULE=obj({**fields('code version prefix'),'dependencies':mapping(S)},[])
REPORT=obj({'days':arr(dto('date reservations:i guests:i cancelled:i no_show:i arrived:i')),'saved':arr(dto('id:i name from to created_at'))})
WIDGET=dto('id origins expires_at created_at duration_minutes:i accent:s? language position max_party_size:i show_brand:bi')
MAIL=dto('enabled:b host port:i security username:s? password_set:b from_address from_name')
REG=dto('enabled:b privacy_url imprint_url revision:i available:b https_ready:b smtp_ready:b public_url')
BILLINGAUTOMATION=obj({'settings':dto('enabled:b live:b send_invoices:b send_reminders:b reminder_days:i revision:i configured:b'),'subscriptions':page(SUB),'deliveries':arr(dto('id:i invoice_id:i kind recipient status attempts:i created_at updated_at')),'products':arr(PRODUCT)})
responses={
 'SupportController@index':page(TICKET),'SupportController@create':ID,'SupportController@show':obj({'ticket':TICKET,'messages':arr(dto('id:i ticket_id:i user_id:i body internal:bi created_at author'))}),
 'DatabaseAccessController@index':arr(CONNECTION),'DatabaseAccessController@reveal':obj(fields('password visible_seconds:i')),
 'ServerController@index':obj({'servers':arr(SERVER),'provisioning_mode':S,'notice':S}),'ServerController@save':SERVER,'ServerController@test':dto('ok:b code message latency_ms:n'),
 'AuditController@index':page(AUDIT),'ReportController@index':REPORT,'ReportController@save':ID,
 'ReservationController@week':obj({'rows':arr(HOURS),'revision':S}),'ReservationController@saveWeek':SAVED,'ReservationController@reservations':arr(RES),'ReservationController@saveReservation':RES,'ReservationController@assignTables':obj({'assigned':I}),'ReservationController@weather':WEATHER,'ReservationController@index':arr(RESOURCE),'ReservationController@save':RESOURCE,
 'WaitlistController@index':arr(WAIT),'WaitlistController@save':WAIT,'WaitlistController@book':obj({'reservation_id':I}),
 'AvailabilityController@index':arr({'anyOf':[dto('id:i room_id:i starts_at ends_at reason created_at updated_at'),obj({**fields('id:i name active:bi'),'table_ids':arr(I)},[])]}),'AvailabilityController@save':ID,
 'WeatherController@settings':obj({'settings':nullable(dto('id:i enabled:bi latitude:n longitude:n mode rain_threshold:i version:i created_at updated_at')),'commercial_key_configured':B}),'WeatherController@save':SAVED,
 'StatusController@index':obj(fields('module state configured:b synchronization_available:b message')),
 'ReleaseController@index':page(RELEASE),'ReleaseController@save':RELEASE,
 'NotificationController@index':NOTIFICATION,'NotificationController@save':NOTIFICATION,'NotificationController@retry':STATUS,
 'WidgetController@list':arr(WIDGET),'WidgetController@create':obj(fields('id token url embed')),'WidgetController@update':obj({'id':S,'updated':B}),
 'OrganizationController@index':arr(dto('id:i name parent_id:i? version:i created_at updated_at')),'OrganizationController@save':ID,'OrganizationController@assign':UPDATED,
 'TenantController@tenants':page(TENANT),'TenantController@createTenant':TENANT,'TenantController@updateTenant':TENANT,'TenantController@retryTenant':TENANT,'TenantController@demoTenant':obj({'tenant':TENANT,'login_url':S}),
 'ProfileController@profile':TENANT,'ProfileController@updateProfile':TENANT,
 'RoleController@index':obj({'families':arr(FAMILY),'roles':arr(ROLE)}),'RoleController@save':ID,'RoleController@activate':obj(fields('status version:i check')),'RoleController@check':obj(fields('status version:i check')),
 'RoleRolloutController@catalog':obj({'permissions':CATALOG}),'RoleRolloutController@apply':obj({'created':arr(dto('tenant_id:i role_id:i')),'synchronized':arr(dto('tenant_id:i role_id:i'))},[]),'RoleRolloutController@preview':obj({'token':S,'preview':dto('name mode'),'expires_in':I}),
 'UserController@roles':arr(obj({**fields('code name locked:b'),'permissions':arr(S)})),'UserController@users':arr(USER),'UserController@createUser':USER,'UserController@updateUser':USER,'UserController@invite':MESSAGE,
 'RestaurantRoleController@index':ROLECAT,'RestaurantRoleController@save':ID,'TeamController@team':arr(USER),'TeamController@createTeam':USER,'TeamController@updateTeam':USER,
 'ModuleController@administration':obj({'products':arr(PRODUCT),'orders':arr(ORDER)}),'ModuleController@confirm':STATUS,'ModuleController@price':STATUS,'ModuleController@catalog':obj({'products':arr(PRODUCT),'orders':arr(ORDER),'entitlements':arr(ENT),'core_modules':arr(S),'payment_mode':S}),'ModuleController@order':ORDER,'ModuleController@activate':obj({'status':S,'operation_id':I},['status']),
 'AutomationController@index':BILLINGAUTOMATION,'AutomationController@settings':STATUS,'AutomationController@retry':STATUS,'AutomationController@send':STATUS,'AutomationController@run':STATUS,'AutomationController@checkout':obj({'url':S}),'AutomationController@cancel':STATUS,'AutomationController@portal':obj({'url':S}),
 'InvoiceController@index':obj({'settings':PARTY,'invoices':page(INVOICE),'subscriptions':arr(ENT),'profiles':arr(PARTY)}),'InvoiceController@customer':obj({'profile':PARTY,'invoices':page(INVOICE),'subscriptions':arr(ENT)}),'InvoiceController@discard':STATUS,'InvoiceController@cancel':obj({'id':I,'number':S}),'InvoiceController@issue':obj({'number':S}),'InvoiceController@draft':ID,'InvoiceController@profile':STATUS,'InvoiceController@settings':STATUS,
 'ExportController@start':obj({'id':S,'status':S,'format':S,'expires_in':I}),'ExportController@status':obj({'id':S,'status':S,'rows':I,'expires_at':S,'format':S}),
 'PlatformController@dashboard':obj({**fields('tenants_total:i tenants_active:i tenants_pending:i users_total:i open_tickets:i'),'recent_audit':arr(AUDIT)}),
 'PlatformController@health':obj({**fields('version php database latency_ms:n queued_jobs:i failed_jobs:i mail_configured:b scheduler_last_seen:s?'),'migrations':arr(dto('migration batch:i'))}),
 'TenantOperationController@index':arr(OPERATION),'TenantOperationController@move':obj({'operation_id':I,'status':S}),'TenantOperationController@moveBatch':obj({'operations':arr(dto('tenant_id:i operation_id:i')),'status':S},[]),
}
responses['SupportController@reply']=responses['SupportController@show']
platform={
 'platform.get.admin_mail_settings':MAIL,'platform.put.admin_mail_settings':MAIL,'platform.post.admin_mail_settings_send_test':MESSAGE,'platform.post.admin_mail_settings_test':MESSAGE,
 'platform.get.admin_modules':arr(MODULE),'platform.get.admin_permissions_families':arr(FAMILY),
 'platform.get.admin_registration_settings':REG,'platform.put.admin_registration_settings':REG,
 'platform.get.admin_module_updates':obj({'repositories':mapping(obj({'installed':S,'versions':mapping(obj({'commit':S,'packages':arr(dto('name version kind source target'))}))},[])),'github_configured':B,'reader_configured':B,'pipeline_configured':B,'registry_url':S,'builds':arr(BUILD)}),
 'platform.post.admin_module_updates_builds':obj(fields('id status')),'platform.post.admin_module_updates_builds_id_refresh':BUILD,'platform.post.admin_module_updates_builds_id_stage':obj(fields('id status')),
 'platform.post.admin_module_updates_preview':obj({'compatible':B,'errors':arr(S),'selection':{'type':'object','additionalProperties':obj(fields('version commit'))},'packages':arr(dto('name version kind source target'))}),'platform.put.admin_module_updates_settings':obj({'saved':B,'reader_token':nullable(S)}),'platform.post.admin_module_updates_sync':dto('repository added:i'),
 'platform.get.admin_system_operations':obj({'available':B,'backups':arr(dto('name path created_at')),'packages':arr(dto('name version')),'jobs':arr(dto('id status action created_at'))},[]),'platform.post.admin_system_operations':obj(fields('id status')),
}
