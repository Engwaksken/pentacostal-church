(function () {
    'use strict';
    const languages = ['Acholi','Alur','Ateso','Dhopadhola (Japadhola)','English','Ik','Kakwa','Karamojong (Ngakarimojong)','Kebu','Kumam','Kupsabiny (Sebei)','Lango','Lugbara','Luganda','Lugisu (Lumasaba)','Lunyole (Nyole)','Lusoga',"Ma'di",'Nubi','Nyangia','Runyankole','Rukiga','Runyoro','Rutooro','Samia','Other'];
    const configs = {
        verses: {title:'Bible Verses', fields:[['reference','Reference','text',true],['verse_text','Verse Text','textarea',true],['translation','Translation','text',true],['display_date','Display Date','date'],['status','Status','status']]},
        prayers: {title:'Church Prayers', fields:[['title','Prayer Title','text',true],['prayer_category','Category','text'],['scripture_reference','Scripture Reference','text'],['prayer_text','Prayer Text','textarea',true],['status','Status','status']]},
        hymns: {title:'Church Songs', fields:[['hymn_number','Song Number','text'],['title','Song Title','text',true],['hymn_book','Song Book','text'],['language','Language','language'],['author','Author / Translator','text'],['tune','Tune / Metre','text'],['theme','Theme / Season','text'],['scripture_reference','Scripture Reference','text'],['study_notes','Study Notes','textarea'],['lyrics','Lyrics','textarea'],['copyright_status','Copyright Basis','copyright'],['copyright_notice','Copyright Notice','text'],['status','Status','status']]},
        order_of_service: {title:'Orders of Service', fields:[['title','Order Title','text',true],['service_type','Service Type','text'],['service_date','Service Date','date',true],['service_time','Start Time','time'],['notes','Notes for Members','textarea'],['first_item','First Service Item (optional)','text'],['status','Status','status']]}
    };

    function node(tag, attrs, text) {
        const el = document.createElement(tag);
        Object.entries(attrs || {}).forEach(([k,v]) => el.setAttribute(k, v));
        if (text !== undefined) el.textContent = text;
        return el;
    }
    function optionList(select, values, placeholder) {
        select.appendChild(node('option', {value:''}, placeholder));
        values.forEach(v => select.appendChild(node('option', {value:v}, v)));
    }
    function init(button) {
        const resource = button.dataset.contentBulk;
        const config = configs[resource];
        if (!config) return;
        const form = document.createElement('form');
        form.className = 'content-bulk-form';
        form.innerHTML = '<header><div><h2></h2><p>Add several records in one batch. The batch is saved together.</p></div><button type="button" class="content-bulk-close" aria-label="Close">×</button></header><div class="content-bulk-rows"></div><div class="content-bulk-actions"><button type="button" class="btn btn-outline content-bulk-add"><i class="fas fa-plus"></i> Add Another</button><span class="content-bulk-count"></span></div><footer><button type="button" class="btn btn-outline content-bulk-close">Cancel</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Entries</button></footer>';
        form.querySelector('h2').textContent = 'Add Multiple ' + config.title;
        const dialog = node('dialog', {class:'content-bulk-dialog'});
        dialog.appendChild(form);
        document.body.appendChild(dialog);
        const rows = form.querySelector('.content-bulk-rows');

        function addRow() {
            if (rows.children.length >= 50) return;
            const card = node('section', {class:'content-bulk-row'});
            const head = node('div', {class:'content-bulk-row-head'});
            const number = node('strong', {}, 'Entry ' + (rows.children.length + 1));
            const remove = node('button', {type:'button',class:'content-bulk-remove', 'aria-label':'Remove entry'}, 'Remove');
            remove.addEventListener('click', () => { card.remove(); renumber(); });
            head.append(number, remove); card.appendChild(head);
            const grid = node('div', {class:'content-bulk-grid'});
            config.fields.forEach(([name,label,type,required]) => {
                const wrap = node('label', {class:type==='textarea'?'span-2':''}, label + (required?' *':''));
                let control;
                if (type === 'textarea') control = node('textarea', {name, rows:'3', ...(required?{required:'required'}:{})});
                else if (type === 'status' || type === 'copyright' || type === 'language') {
                    control = node('select', {name});
                    if (type === 'status') optionList(control, ['Draft','Published'], 'Choose status');
                    if (type === 'copyright') optionList(control, ['Not Entered','Public Domain','Licensed','Permission Granted'], 'Choose basis');
                    if (type === 'language') optionList(control, languages, 'Select a Ugandan language');
                    if (required) control.required = true;
                    if (name === 'status') control.value = 'Draft';
                    if (name === 'copyright_status') control.value = 'Not Entered';
                } else {
                    control = node('input', {name,type:type==='text'?'text':type,...(required?{required:'required'}:{})});
                }
                wrap.appendChild(control); grid.appendChild(wrap);
            });
            card.appendChild(grid); rows.appendChild(card); renumber();
        }
        function renumber() {
            Array.from(rows.children).forEach((card,i)=>card.querySelector('.content-bulk-row-head strong').textContent='Entry '+(i+1));
            form.querySelector('.content-bulk-count').textContent=rows.children.length+' / 50 entries';
        }
        form.querySelector('.content-bulk-add').addEventListener('click', addRow);
        form.querySelectorAll('.content-bulk-close').forEach(el=>el.addEventListener('click',()=>dialog.close()));
        dialog.addEventListener('click', e=>{if(e.target===dialog)dialog.close();});
        form.addEventListener('submit', async e=>{
            e.preventDefault(); if(!form.reportValidity()) return;
            const entries=Array.from(rows.children).map(card=>{
                const entry={}; card.querySelectorAll('[name]').forEach(input=>entry[input.name]=input.value);
                if(resource==='order_of_service') entry.items=entry.first_item?[{title:entry.first_item,led_by:'',details:'',hymn_id:''}]:[];
                delete entry.first_item;
                return entry;
            });
            const csrf=document.querySelector('input[name="csrf_token"]')?.value||'';
            const data=new FormData(); data.append('csrf_token',csrf); data.append('action','save_bulk'); data.append('resource',resource); data.append('entries',JSON.stringify(entries));
            const save=form.querySelector('button[type="submit"]'); save.disabled=true;
            try { await fetch('process.php',{method:'POST',body:data,credentials:'same-origin'}); window.location.reload(); }
            catch (_) { save.disabled=false; window.alert('Could not save the batch. Please check your connection and try again.'); }
        });
        button.addEventListener('click',()=>{rows.replaceChildren();addRow();addRow();dialog.showModal();});
    }
    document.querySelectorAll('[data-content-bulk]').forEach(init);
})();
