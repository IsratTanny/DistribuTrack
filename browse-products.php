<?php
// file: browse-products.php
declare(strict_types=1);
session_start();

$shopkeeperId = isset($_SESSION['shopkeeper_id']) ? (int)$_SESSION['shopkeeper_id'] : 0;
$initialQuery = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>DistribuTrack - Browse Products</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
  <style>
    body { font-family: 'Inter', sans-serif; }
    .product-card{ transition: transform .2s ease, box-shadow .2s ease; }
    .product-card:hover { transform: translateY(-2px); box-shadow: 0 10px 15px -3px rgba(0,0,0,.1), 0 4px 6px -2px rgba(0,0,0,.05); }
    .toast { position: fixed; right: 1rem; bottom: 1rem; background:#111827; color:#fff; padding:.75rem 1rem; border-radius:.5rem; opacity:0; transform: translateY(10px); transition: all .25s ease; }
    .toast.show { opacity:1; transform: translateY(0); }
  </style>
</head>
<body class="bg-gray-50 min-h-screen">

  <!-- Navbar -->
  <nav class="bg-blue-600 text-white p-4 flex justify-between items-center shadow-md">
    <div class="flex items-center space-x-6">
      <div class="flex items-center space-x-3">
        <img src="./DistribuTrack.png" alt="DistribuTrack logo" class="h-10 rounded-md">
        <span class="text-xl font-semibold">DistribuTrack</span>
      </div>
    </div>
    <a href="shopkeeper-dashboard.html" class="flex items-center space-x-2 bg-white bg-opacity-20 hover:bg-opacity-30 px-4 py-2 rounded-lg transition">
      <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
        <path fill-rule="evenodd" d="M9.707 16.707a1 1 0 01-1.414 0l-6-6a1 1 0 010-1.414l6-6a1 1 0 011.414 1.414L5.414 9H17a1 1 0 110 2H5.414l4.293 4.293a1 1 0 010 1.414z" clip-rule="evenodd" />
      </svg>
      <span>Dashboard</span>
    </a>
  </nav>

  <div class="container mx-auto px-4 py-8">
    <div class="text-center mb-8">
      <h1 class="text-3xl font-bold text-gray-800 mb-2">Available Products</h1>
      <p class="text-gray-600">Products uploaded by distributors</p>
    </div>

    <div class="bg-white rounded-lg shadow-sm p-4 mb-8">
      <div class="flex flex-col md:flex-row gap-4">
        <div class="relative flex-grow">
          <input
            type="text"
            id="searchInput"
            placeholder="Search products…"
            class="pl-4 w-full border border-gray-200 rounded-lg p-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
            value="<?php echo htmlspecialchars($initialQuery, ENT_QUOTES); ?>"
          >
        </div>
      </div>
    </div>

    <div id="productsGrid" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6"></div>

    <div id="emptyState" class="hidden text-center py-12">
      <h3 class="mt-4 text-lg font-medium text-gray-900">No products found</h3>
      <p class="mt-1 text-gray-500">Try adjusting your search or check back later.</p>
    </div>
  </div>

  <!-- Toast -->
  <div id="toast" class="toast" role="status" aria-live="polite"></div>

  <script>
    const grid = document.getElementById('productsGrid');
    const emptyState = document.getElementById('emptyState');
    const searchInput = document.getElementById('searchInput');
    const toast = document.getElementById('toast');

    const SHOPKEEPER_ID = <?php echo (int)$shopkeeperId; ?>;

    let products = [];

    function showToast(msg) {
      toast.textContent = msg;
      toast.classList.add('show');
      setTimeout(() => toast.classList.remove('show'), 2200);
    }

    function normalizePayload(payload) {
      // Accept either an array or {success:true, data:[...]} or any mix.
      if (Array.isArray(payload)) return payload;
      if (payload && typeof payload === 'object') {
        if (Array.isArray(payload.data)) return payload.data;
        if (Array.isArray(payload.items)) return payload.items;
      }
      return [];
    }

    function mapProduct(p) {
      // Normalize field names coming from backend
      const id   = p.id ?? p.product_id ?? null;
      const name = p.product_name ?? p.name ?? 'Unnamed';
      const desc = p.description ?? '';
      const img  = p.image_path ?? p.image ?? '';
      const price = Number(p.price ?? p.unit_price ?? 0);
      const stock = Number(p.quantity ?? p.stock ?? 0);
      const distName = p.distributor ?? p.distributor_name ?? (p.distributor_id ? `Distributor #${p.distributor_id}` : '—');
      return { id, name, desc, img, price, stock, distributor: distName };
    }

    async function loadProducts() {
      try {
        const res = await fetch('fetch_inventory.php', { headers: { 'Accept': 'application/json' }});
        const raw = await res.json();
        const list = normalizePayload(raw).map(mapProduct);

        if (list.length) {
          products = list;
          // Apply initial query filter if present
          const q = (searchInput.value || '').trim().toLowerCase();
          render(q ? filter(products, q) : products);
        } else {
          products = [];
          render([]);
        }
      } catch (e) {
        console.error('Failed to load products:', e);
        products = [];
        render([]);
      }
    }

    function filter(list, term) {
      const t = term.toLowerCase();
      return list.filter(p =>
        p.name.toLowerCase().includes(t) ||
        p.distributor.toLowerCase().includes(t) ||
        p.desc.toLowerCase().includes(t)
      );
    }

    function priceFmt(n) {
      try {
        return new Intl.NumberFormat('bn-BD', { style: 'currency', currency: 'BDT', maximumFractionDigits: 2 }).format(n);
      } catch {
        return `৳${Number(n).toFixed(2)}`;
      }
    }

    function render(list) {
      grid.innerHTML = '';
      if (!list.length) {
        emptyState.classList.remove('hidden');
        return;
      }
      emptyState.classList.add('hidden');

      const fr = document.createDocumentFragment();

      list.forEach(p => {
        const card = document.createElement('div');
        card.className = 'product-card bg-white rounded-lg shadow-md overflow-hidden border border-gray-100 flex flex-col';

        const imgSrc = p.img || 'https://via.placeholder.com/600x400?text=No+Image';
        card.innerHTML = `
          <img src="${imgSrc}" alt="${p.name.replace(/"/g, '&quot;')}" class="w-full h-48 object-cover">
          <div class="p-4 flex-1 flex flex-col">
            <div class="flex justify-between items-start">
              <h3 class="text-lg font-semibold text-gray-800">${p.name}</h3>
              <span class="text-sm text-gray-500 truncate max-w-[150px]" title="${p.distributor}">${p.distributor}</span>
            </div>
            <p class="text-gray-500 text-sm mt-1 line-clamp-2">${p.desc || ''}</p>
            <div class="mt-4 flex justify-between items-center">
              <span class="text-xl font-bold text-blue-600">${priceFmt(p.price)}</span>
              <span class="text-sm ${p.stock > 0 ? 'text-green-600' : 'text-red-600'}">
                ${p.stock > 0 ? `${p.stock} in stock` : 'Out of stock'}
              </span>
            </div>

            <div class="mt-4 flex items-center justify-between">
              <div class="flex items-center space-x-2">
                <label for="qty-${p.id}" class="sr-only">Quantity</label>
                <input id="qty-${p.id}" type="number" min="1" value="1" ${p.stock ? `max="${p.stock}"` : ''} class="w-20 border border-gray-200 rounded-md p-2">
              </div>
              <button
                class="add-btn bg-blue-600 text-white px-4 py-2 rounded-md hover:bg-blue-700 transition disabled:opacity-50 disabled:cursor-not-allowed"
                data-id="${p.id}"
                ${(!SHOPKEEPER_ID || p.stock < 1) ? 'disabled' : ''}>
                ${SHOPKEEPER_ID ? 'Add to Cart' : 'Login to Buy'}
              </button>
            </div>
          </div>
        `;
        fr.appendChild(card);
      });

      grid.appendChild(fr);

      // Bind all add-to-cart buttons
      grid.querySelectorAll('.add-btn').forEach(btn => {
        btn.addEventListener('click', async (e) => {
          const id = Number(btn.getAttribute('data-id'));
          const qtyInput = btn.closest('.product-card').querySelector('input[type="number"]');
          const qty = Math.max(1, Number(qtyInput?.value || 1));

          if (!SHOPKEEPER_ID) {
            showToast('Please log in as a shopkeeper to add items.');
            return;
          }

          try {
            btn.disabled = true;
            const res = await fetch('add_to_cart.php', {
              method: 'POST',
              headers: {'Content-Type': 'application/json', 'Accept':'application/json'},
              body: JSON.stringify({
                shopkeeper_id: SHOPKEEPER_ID,
                product_id: id,
                quantity: qty
              })
            });
            const result = await res.json();
            if (result?.success) {
              showToast('Item added to cart.');
            } else {
              showToast(result?.error || 'Could not add to cart.');
            }
          } catch (err) {
            console.error(err);
            showToast('An error occurred.');
          } finally {
            btn.disabled = false;
          }
        });
      });
    }

    searchInput.addEventListener('input', e => {
      const term = e.target.value;
      render(filter(products, term));
    });

    loadProducts();
  </script>
</body>
</html>
