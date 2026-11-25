import {TestBed} from '@angular/core/testing';
import {SensitivityChartComponent} from './sensitivity-chart.component';
describe('Sensitivity chart evidence',()=>{
 it('preserves actual shifts and zero without treating failures as zero',()=>{const f=TestBed.createComponent(SensitivityChartComponent);f.componentRef.setInput('sensitivity',{status:'partial',runs:[{channel:'A',scale:1.1,max_share_shift_pp:12.5},{channel:'B',scale:.9,max_share_shift_pp:0},{channel:'C',scale:1.1,status:'infeasible'}]});f.detectChanges();expect(f.componentInstance.axisMax()).toBe(12.5);expect(f.nativeElement.querySelectorAll('rect').length).toBe(2);expect(f.nativeElement.textContent).toContain('Undefined');expect(f.nativeElement.textContent).toContain('Not computed');expect(f.nativeElement.textContent).toContain('not unchanged response');});
 it('does not invent chart data when sensitivity abstains',()=>{const f=TestBed.createComponent(SensitivityChartComponent);f.componentRef.setInput('sensitivity',{status:'not_computable'});f.detectChanges();expect(f.nativeElement.querySelector('svg')).toBeNull();expect(f.componentInstance.axisMax()).toBe(1);});
});
