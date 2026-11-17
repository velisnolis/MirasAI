import test from 'node:test';
import assert from 'node:assert/strict';
import { createRouterHandler } from '../src/mcp-stdio.mjs';
const registry = { default_site_id: 'first', sites: ['first', 'second'].map(site_id => ({site_id, platform:'wordpress', url:`https://${site_id}.test/mcp`})) };
function fixture(options = {}) {
  const calls = [];
  const client = {
    toolsList: async () => ({tools: [
      {name:'read', metadata:{risk_level:'read'}},
      {name:'write', metadata:{risk_level:'guarded_write'}},
      {name:'unknown'},
    ]}),
    callTool: async (name, args) => {
      calls.push({name, args});
      if (args.fail) throw new Error('Response lost');
      return {structuredContent:{action:'updated', dry_run:false, new_etag:'new', ...(args.verifyFail ? {error:'Readback failed', write_state:'saved'} : {})}, ...(args.verifyFail ? {isError:true} : {})};
    },
  };
  const handler = createRouterHandler(options.single ? {...registry, sites:[registry.sites[0]]} : registry, { clientFactory: () => client, ...(options.scoped ? {siteId:'second'} : {}) });
  return {calls, call:async (name, args={}) => (await handler({id:1,method:'tools/call',params:{name,arguments:args}})).result};
}
test('multi-site writes, unknown tools and Style previews require an explicit destination', async () => {
  const f=fixture();
  for (const name of ['write','unknown','mirasai/style-update']) {
    const r=await f.call(name,{dry_run:true});
    assert.equal(r.structuredContent.code,'site_id_required');
    assert.equal(r.structuredContent.write_state,'not_sent');
  }
  assert.equal(f.calls.length,0);
});
test('read-only batch uses default; mixed batch is rejected before its first call', async () => {
  const f=fixture();
  const r=await f.call('mirasai/batch',{calls:[{name:'read'},{name:'write'}]});
  assert.equal(r.structuredContent.code,'site_id_required');
  assert.equal(f.calls.length,0);
  assert.equal((await f.call('mirasai/batch',{calls:[{name:'read'}]})).isError,undefined);
});
test('explicit, single-site and scoped writes remain supported; blank ids fail', async () => {
  for (const options of [{single:true},{scoped:true},{}]) {
    const f=fixture(options);
    assert.equal((await f.call('write', options.single || options.scoped ? {} : {site_id:'second'})).isError,undefined);
    assert.equal(f.calls.length,1);
  }
  const f=fixture();
  assert.equal((await f.call('write',{site_id:''})).structuredContent.code,'invalid_arguments');
  assert.equal(f.calls.length,0);
});
test('lost write response is unknown; returned saved failure keeps its evidence', async () => {
  const f=fixture();
  const lost=await f.call('write',{site_id:'second',fail:true});
  assert.equal(lost.structuredContent.write_state,'unknown');
  const saved=await f.call('write',{site_id:'second',verifyFail:true});
  assert.equal(saved.structuredContent.write_state,'saved');
  assert.equal(saved.structuredContent.new_etag,'new');
  assert.equal(saved.isError,true);
});
test('partial batch retains successful writes and warns against replaying it', async () => {
  const f=fixture();
  const r=await f.call('mirasai/batch',{site_id:'second',calls:[{name:'write'},{name:'write',arguments:{fail:true}},{name:'write'}]});
  assert.equal(r.structuredContent.transactional,false);
  assert.equal(r.structuredContent.results[0].result.action,'updated');
  assert.equal(r.structuredContent.results[1].result.write_state,'unknown');
  assert.equal(r.structuredContent.not_run,1);
  assert.equal(f.calls.length,2);
});
test('Style destination rejection does not load a client or credentials', async () => {
  const handler=createRouterHandler(registry,{clientFactory:()=>{throw new Error('must not load');}});
  const r=await handler({id:1,method:'tools/call',params:{name:'mirasai/style-update',arguments:{if_match:'etag',dry_run:false,confirm_guarded_write:true}}});
  assert.equal(r.result.structuredContent.code,'site_id_required');
});
test('failed metadata discovery cannot authorize implicit execution', async () => {
  let called=false;
  const handler=createRouterHandler(registry,{clientFactory:()=>({toolsList:async()=>{throw new Error('offline');},callTool:async()=>{called=true;}})});
  const r=await handler({id:1,method:'tools/call',params:{name:'write',arguments:{}}});
  assert.equal(r.result.structuredContent.code,'site_id_required');
  assert.equal(called,false);
});
