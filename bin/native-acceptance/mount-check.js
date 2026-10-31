'use strict';
// Runs the actual merchant inline script with an explicitly synthetic SDK.
const vm = require('node:vm');
let input = '';
process.stdin.on('data', chunk => { input += chunk; });
process.stdin.on('end', () => {
  const { script, paymentId } = JSON.parse(input);
  let calls = 0, removed = 0;
  vm.runInNewContext(script, {
    window: { Lunixi: { checkout: { create(config) {
      if (config.type !== 'payment' || config.options.paymentId !== paymentId ||
          typeof config.options.token !== 'string' || !config.options.fixtureMultiCard) {
        throw new Error('Native form configuration mismatch');
      }
      return { mount(selector) { if (selector !== '#lunixi-checkout-form') throw new Error('Mount mismatch'); calls++; } };
    } } } },
    document: { currentScript: { parentNode: { removeChild() { removed++; } } } }
  }, { timeout: 1000 });
  if (calls !== 1 || removed !== 1) throw new Error('Native mount cardinality mismatch');
  process.stdout.write(JSON.stringify({ mountedOnce: true, originalConfigurationPreserved: true }));
});
