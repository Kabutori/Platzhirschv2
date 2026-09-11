import {test,expect} from '@playwright/test';
for(const admin of [true,false])test(`billing ${admin?'admin':'restaurant'} navigation and invoice view`,async({page})=>{
 await page.route('**/api/**',async route=>{const path=new URL(route.request().url()).pathname;let body={};
 if(path.endsWith('/auth/me'))body={id:1,name:'Test',role:admin?'system_admin':'restaurant_admin',tenant_id:1,permissions:['modules.manage'],enabled_modules:[]};
 else if(path.endsWith('/billing/documents')||path.endsWith('/restaurant/billing'))body={settings:{revision:0},profile:{revision:0},profiles:[],subscriptions:[],invoices:{data:[{id:1,tenant_id:1,number:'PH-2026-000001',status:'issued',kind:'invoice',total_cents:11900}],last_page:1}};
 await route.fulfill({contentType:'application/json',body:JSON.stringify(body)});
 });
 await page.goto(admin?'/admin/login':'/restaurant/login');
 await page.getByRole('button',{name:'Abrechnung',exact:true}).click();
 await expect(page.getByText('PH-2026-000001 ·')).toBeVisible();
 await expect(page.getByRole('button',{name:'Druckansicht / PDF'})).toBeVisible();
 if(!admin)await expect(page.getByText('Rechnung stornieren',{exact:true})).toHaveCount(0);
 await page.screenshot({path:`test-results/design-billing-${admin?'admin':'restaurant'}.png`,fullPage:true});
 await page.getByRole('button',{name:admin?'Aussteller':'Rechnungsadresse',exact:true}).click();
 await expect(page.getByLabel('Firmenname')).toBeVisible();
 await page.setViewportSize({width:390,height:844});
 expect(await page.evaluate(()=>document.documentElement.scrollWidth)).toBeLessThanOrEqual(390);
});
