import {TestBed} from '@angular/core/testing';
import {ResponseProfilesComponent} from './response-profiles.component';
import {describe,it,expect} from 'vitest';
describe('Response profiles',()=>{
 it('retains exact support and selected modeled values without implying confidence',()=>{const f=TestBed.createComponent(ResponseProfilesComponent);f.componentRef.setInput('currency','USD');f.componentRef.setInput('profiles',{status:'ok',rows:[{entity_key:'a',family:'log',support_min_spend:20,support_max_spend:80,maximum_spend:100,selected_spend:50,selected_response:7,points:[{spend:0,modeled_response:0,outside_observed_support:true},{spend:100,modeled_response:10,outside_observed_support:true}]}]});f.detectChanges();expect(f.nativeElement.textContent).toContain('Selected spend: 50.00');expect(f.nativeElement.textContent).toContain('Amber: extrapolation');expect(f.nativeElement.querySelector('circle')).not.toBeNull();expect(f.componentInstance.supportWidth({support_min_spend:20,support_max_spend:200,maximum_spend:100})).toBe(456);});
 it('omits null marginal values from axis rather than treating unavailable as zero',()=>{const c=TestBed.createComponent(ResponseProfilesComponent).componentInstance;expect(c.finite(null)).toBe(false);expect(c.marginalMax({points:[{modeled_marginal_response:null},{modeled_marginal_response:4}]})).toBe(4);expect(c.marginalY(2,{points:[{modeled_marginal_response:4}]})).toBe(112.5);});

});
