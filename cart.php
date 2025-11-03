<?php
// file: cart.php
declare(strict_types=1);
session_start();

// Require a logged-in shopkeeper
if (!isset($_SESSION['shopkeeper_id'])) {
  // If you prefer redirect instead of a plain message, uncomment the header line below
  // header('Location: shopkeeper.html'); exit;
  http_response_code(401);
  echo 'Unauthorized. Please log in as a shopkeeper.';
  exit;
}

$SHOPKEEPER_ID = (int) $_SESSION['shopkeeper_id'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>DistribuTrack — Your Cart</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
  <style>
    body { font-family: 'Inter', sans-serif; }
    .card { transition: box-shadow .2s ease, transform .2s ease; }
    .card:hover { transform: translateY(-2px); box-shadow: 0 10px 15px -3px rgba(0,0,0,.1), 0 4px 6px -2px rgba(0,0,0,.05); }
    .qty-btn { width: 2.25rem; height: 2.25rem; }
    .toast { position: fixed; right: 1rem; bottom: 1rem; background:#111827; color:#fff; padding:.75rem 1rem; border-radius:.5rem; opacity:0; transform: translateY(10px); transition: all .25s ease; z-index: 50;}
    .toast.show { opacity:1; transform: translateY(0); }
  </style>
</head>
<body class="bg-gray-50 min-h-screen">

  <!-- Navbar -->
  <nav class="bg-blue-600 text-white p-4 flex justify-between items-center shadow-md">
    <div class="flex items-center space-x-3">
      <img src="./DistribuTrack.png" alt="DistribuTrack logo" class="h-10 rounded-md" />
      <span class="text-xl font-semibold">DistribuTrack</span>
    </div>
    <div class="flex items-center space-x-3">
      <a href="browse-products.php" class="bg-white bg-opacity-20 hover:bg-opacity-30 px-4 py-2 rounded-lg transition">Browse Products</a>
      <a href="shopkeeper-dashboard.html" class="bg-white bg-opacity-20 hover:bg-opacity-30 px-4 py-2 rounded-lg transition">Dashboard</a>
    </div>
  </nav>

  <main class="container mx-auto px-4 py-8 max-w-6xl">
    <div class="mb-8">
      <h1 class="text-3xl font-bold text-gray-800">Your Cart</h1>
      <p class="text-gray-600">Review your items and proceed to checkout</p>
    </div>

    <div id="cartWrapper" class="grid grid-cols-1 lg:grid-cols-3 gap-6">
      <!-- Cart Items -->
      <section class="lg:col-span-2">
        <div id="emptyState" class="hidden bg-white rounded-lg border border-dashed border-gray-300 p-10 text-center">
          <h3 class="text-lg font-semibold text-gray-800">Your cart is empty</h3>
          <p class="text-gray-500 mt-2">Browse products and add items to your cart.</p>
          <a href="browse-products.php" class="inline-block mt-4 bg-blue-600 text-white px-4 py-2 rounded-md hover:bg-blue-700 transition">Browse Products</a>
        </div>

        <div id="itemsList" class="space-y-4"></div>
      </section>

      <!-- Summary -->
      <aside class="card bg-white rounded-lg shadow-sm border border-gray-100 p-6 h-fit">
        <h2 class="text-xl font-semibold text-gray-800 mb-4">Order Summary</h2>
        <div class="space-y-2 text-sm">
          <div class="flex justify-between">
            <span class="text-gray-600">Subtotal</span>
            <span id="subtotal" class="font-medium">৳0.00</span>
          </div>
          <!-- If VAT/Delivery needed, add rows here -->
        </div>
        <hr class="my-4">
        <div class="flex justify-between text-lg">
          <span class="font-semibold text-gray-800">Total</span>
          <span id="total" class="font-bold text-gray-900">৳0.00</span>
        </div>
        <button id="checkoutBtn" class="mt-6 w-full bg-blue-600 text-white py-3 rounded-md hover:bg-blue-700 transition disabled:opacity-50 disabled:cursor-not-allowed">
          Place Order
        </button>
        <p class="text-xs text-gray-500 mt-3">Orders may be split by distributor automatically.</p>
      </aside>
    </div>
  </main>

  <!-- Toast -->
  <div id="toast" class="toast" role="status" aria-live="polite"></div>

  <script>
    const SHOPKEEPER_ID = <?php echo (int) $SHOPKEEPER_ID; ?>;

    const itemsList = document.getElementById('itemsList');
    const emptyState = document.getElementById('emptyState');
    const subtotalEl = document.getElementById('subtotal');
    const totalEl = document.getElementById('total');
    const checkoutBtn = document.getElementById('checkoutBtn');
    const toast = document.getElementById('toast');

    let cartItems = []; // normalized shape

    function showToast(msg) {
      toast.textContent = msg;
      toast.classList.add('show');
      setTimeout(() => toast.classList.remove('show'), 2200);
    }

    function priceBDT(n) {
      try {
        return new Intl.NumberFormat('bn-BD', { style: 'currency', currency: 'BDT', maximumFractionDigits: 2 }).format(Number(n || 0));
      } catch {
        const v = Number(n || 0).toFixed(2);
        return `৳${v}`;
      }
    }

    function normalizePayload(payload) {
      if (Array.isArray(payload)) return payload;
      if (payload && typeof payload === 'object') {
        if (Array.isArray(payload.data)) return payload.data;
        if (Array.isArray(payload.items)) return payload.items;
      }
      return [];
    }

    function mapItem(r) {
      // Try to support several possible keys
      const cartId = r.cart_id ?? r.id ?? null;
      const productId = r.product_id ?? r.pid ?? null;
      const name = r.product_name ?? r.name ?? 'Unnamed';
      const qty = Number(r.quantity ?? r.qty ?? 0);
      const price = Number(r.price ?? r.unit_price ?? 0);
      const line = r.line_total != null ? Number(r.line_total) : price * qty;
      const img = r.image_path ?? r.image ?? '';
      const distributorId = r.distributor_id ?? null;
      const distributor = r.distributor ?? r.distributor_name ?? (distributorId ? `Distributor #${distributorId}` : '—');
      return { cartId, productId, name, qty, price, line, img, distributorId, distributor };
    }

    function computeTotals(list) {
      const sub = list.reduce((s, it) => s + (Number(it.price) * Number(it.qty)), 0);
      return { subtotal: sub, total: sub }; // Extend if delivery/VAT added
    }

    function render(list) {
      itemsList.innerHTML = '';
      if (!list.length) {
        emptyState.classList.remove('hidden');
        checkoutBtn.disabled = true;
        subtotalEl.textContent = priceBDT(0);
        totalEl.textContent = priceBDT(0);
        return;
      }
      emptyState.classList.add('hidden');

      const fr = document.createDocumentFragment();
      list.forEach(it => {
        const row = document.createElement('div');
        row.className = 'card bg-white rounded-lg shadow-sm border border-gray-100 p-4 flex items-center gap-4';

        const imgSrc = it.img || 'https://via.placeholder.com/120x120?text=No+Image';
        row.innerHTML = `
          <img src="${imgSrc}" alt="${it.name.replace(/"/g,'&quot;')}" class="w-24 h-24 object-cover rounded-md border">
          <div class="flex-1">
            <div class="flex justify-between items-start">
              <div>
                <h3 class="text-lg font-semibold text-gray-800">${it.name}</h3>
                <p class="text-xs text-gray-500 mt-0.5"> ${it.distributor} </p>
              </div>
              <button class="removeBtn text-red-600 hover:text-red-700 text-sm" data-id="${it.cartId}">
                Remove
              </button>
            </div>

            <div class="mt-3 flex items-center justify-between">
              <div class="flex items-center border rounded-md">
                <button class="dec qty-btn flex items-center justify-center px-2" data-id="${it.cartId}" aria-label="Decrease quantity">−</button>
                <input type="number" min="1" value="${it.qty}" class="qtyInput w-16 text-center border-l border-r" data-id="${it.cartId}">
                <button class="inc qty-btn flex items-center justify-center px-2" data-id="${it.cartId}" aria-label="Increase quantity">+</button>
              </div>
              <div class="text-right">
                <div class="text-sm text-gray-500">Unit: ${priceBDT(it.price)}</div>
                <div class="text-lg font-semibold text-gray-800"> ${priceBDT(it.price * it.qty)} </div>
              </div>
            </div>
          </div>
        `;

        fr.appendChild(row);
      });
      itemsList.appendChild(fr);

      // Bind remove
      itemsList.querySelectorAll('.removeBtn').forEach(btn => {
        btn.addEventListener('click', async () => {
          const id = Number(btn.getAttribute('data-id'));
          await removeItem(id);
        });
      });

      // Bind +/- and quantity input
      itemsList.querySelectorAll('.dec').forEach(btn => {
        btn.addEventListener('click', async () => {
          const id = Number(btn.getAttribute('data-id'));
          const input = itemsList.querySelector(`.qtyInput[data-id="${id}"]`);
          const newVal = Math.max(1, Number(input.value) - 1);
          input.value = newVal;
          await updateQty(id, newVal);
        });
      });
      itemsList.querySelectorAll('.inc').forEach(btn => {
        btn.addEventListener('click', async () => {
          const id = Number(btn.getAttribute('data-id'));
          const input = itemsList.querySelector(`.qtyInput[data-id="${id}"]`);
          const newVal = Math.max(1, Number(input.value) + 1);
          input.value = newVal;
          await updateQty(id, newVal);
        });
      });
      itemsList.querySelectorAll('.qtyInput').forEach(inp => {
        let debounce;
        inp.addEventListener('input', () => {
          clearTimeout(debounce);
          debounce = setTimeout(async () => {
            const id = Number(inp.getAttribute('data-id'));
            const val = Math.max(1, Number(inp.value || 1));
            inp.value = val;
            await updateQty(id, val);
          }, 350);
        });
      });

      // Totals
      const t = computeTotals(list);
      subtotalEl.textContent = priceBDT(t.subtotal);
      totalEl.textContent = priceBDT(t.total);
      checkoutBtn.disabled = false;
    }

    async function loadCart() {
      try {
        const res = await fetch('fetch_cart.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
          body: JSON.stringify({ shopkeeper_id: SHOPKEEPER_ID })
        });
        const raw = await res.json();
        const list = normalizePayload(raw).map(mapItem);

        cartItems = list;
        render(cartItems);
      } catch (e) {
        console.error(e);
        cartItems = [];
        render(cartItems);
      }
    }

    async function updateQty(cartId, qty) {
      try {
        const res = await fetch('update_cart.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
          body: JSON.stringify({ cart_id: cartId, quantity: qty, shopkeeper_id: SHOPKEEPER_ID })
        });
        const result = await res.json();
        if (!result?.success) {
          showToast(result?.error || 'Failed to update quantity.');
          // reload to show server truth
          await loadCart();
          return;
        }
        // update local model and re-render quickly
        const it = cartItems.find(i => i.cartId === cartId);
        if (it) it.qty = qty;
        render(cartItems);
      } catch (e) {
        console.error(e);
        showToast('Error updating quantity.');
      }
    }

    async function removeItem(cartId) {
      try {
        const res = await fetch('remove_from_cart.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
          body: JSON.stringify({ cart_id: cartId, shopkeeper_id: SHOPKEEPER_ID })
        });
        const result = await res.json();
        if (!result?.success) {
          showToast(result?.error || 'Failed to remove item.');
          return;
        }
        cartItems = cartItems.filter(i => i.cartId !== cartId);
        render(cartItems);
        showToast('Item removed.');
      } catch (e) {
        console.error(e);
        showToast('Error removing item.');
      }
    }

    checkoutBtn.addEventListener('click', async () => {
      if (!cartItems.length) return;
      checkoutBtn.disabled = true;
      try {
        // Minimal API — server will create orders (and possibly split by distributor) from this shopkeeper's cart
        const res = await fetch('place_order.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
          body: JSON.stringify({ shopkeeper_id: SHOPKEEPER_ID })
        });
        const result = await res.json();
        if (result?.success) {
          showToast('Order placed successfully.');
          // Optional: redirect after a short delay (e.g., to dashboard or orders page)
          setTimeout(() => {
            // If you implement an orders page, change the destination below:
            window.location.href = 'shopkeeper-dashboard.html';
          }, 800);
        } else {
          showToast(result?.error || 'Could not place order.');
        }
      } catch (e) {
        console.error(e);
        showToast('Error placing order.');
      } finally {
        checkoutBtn.disabled = false;
      }
    });

    // Initial load
    loadCart();
  </script>
</body>
</html>
