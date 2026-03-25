<?php
// print_builder.php - MASTER CSS INTEGRATED
include 'config.php';
session_start();

if (!isset($_SESSION['admin_id'])) {
    header('location:login.php');
    exit;
}

// --- AJAX: LOAD TEMPLATE ---
if (isset($_POST['action']) && $_POST['action'] == 'load_template') {
    ob_clean();
    header('Content-Type: application/json');
    $id = (int)$_POST['id'];
    $res = $conn->query("SELECT * FROM print_templates WHERE id = $id");
    if ($res && $res->num_rows > 0) {
        echo json_encode(['success' => true, 'data' => $res->fetch_assoc()]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Template not found']);
    }
    exit;
}

// --- AJAX: SAVE OR UPDATE TEMPLATE ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'save_template') {
    ob_clean();
    header('Content-Type: application/json');

    $id = (int)$_POST['template_id'];
    $name = trim($_POST['template_name']);
    $type = trim($_POST['doc_type']);
    $w = (int)$_POST['width_mm'];
    $h = (int)$_POST['height_mm'];
    $printer = trim($_POST['printer_name']);
    $layout = $_POST['layout_data'];

    if ($id > 0) {
        $stmt = $conn->prepare("UPDATE print_templates SET template_name=?, doc_type=?, width_mm=?, height_mm=?, printer_name=?, layout_data=? WHERE id=?");
        $stmt->bind_param("ssiissi", $name, $type, $w, $h, $printer, $layout, $id);
        $msg = "Template Updated Successfully!";
    } else {
        $stmt = $conn->prepare("INSERT INTO print_templates (template_name, doc_type, width_mm, height_mm, printer_name, layout_data) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssiiss", $name, $type, $w, $h, $printer, $layout);
        $msg = "New Template Created Successfully!";
    }

    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => $msg]);
    } else {
        echo json_encode(['success' => false, 'error' => $stmt->error]);
    }
    exit;
}

$templates_list = [];
$t_query = $conn->query("SELECT id, template_name, doc_type FROM print_templates ORDER BY template_name ASC");
if ($t_query) {
    while ($r = $t_query->fetch_assoc()) {
        $templates_list[] = $r;
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Print Template Builder | Trishe Agro</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="css/admin_style.css">

    <style>
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
            overflow-x: hidden;
        }

        .page-header-box {
            background: #fff;
            padding: 20px 25px;
            border-radius: 8px;
            border: 1px solid var(--border);
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        .page-title {
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--text-main);
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .builder-grid {
            display: grid;
            grid-template-columns: 350px 1fr;
            gap: 24px;
            align-items: start;
        }

        h3 {
            margin: 0 0 15px 0;
            font-size: 1rem;
            color: var(--primary);
            border-bottom: 2px dashed var(--border);
            padding-bottom: 10px;
            font-weight: 800;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .form-grid {
            display: flex;
            gap: 10px;
            margin-bottom: 15px;
        }

        /* Tag Buttons Styling */
        .var-container {
            max-height: 280px;
            overflow-y: auto;
            padding-right: 5px;
            margin-bottom: 20px;
            border: 1px solid var(--border);
            padding: 15px;
            border-radius: var(--radius);
            background: #f8fafc;
        }

        .var-container::-webkit-scrollbar {
            width: 6px;
        }

        .var-container::-webkit-scrollbar-track {
            background: transparent;
        }

        .var-container::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 10px;
        }

        .btn-var {
            background: #fff;
            color: var(--text-main);
            border: 1px solid var(--border);
            text-align: left;
            font-family: monospace;
            font-size: 0.85rem;
            margin-bottom: 8px;
            justify-content: flex-start;
            padding: 8px 12px;
            font-weight: 700;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.02);
            width: 100%;
            border-radius: 6px;
            cursor: pointer;
            transition: 0.2s;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .btn-var:hover {
            border-color: var(--primary);
            color: var(--primary);
            transform: translateY(-1px);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }

        .btn-var i {
            width: 15px;
            text-align: center;
            color: var(--text-muted);
        }

        .btn-var:hover i {
            color: var(--primary);
        }

        /* ALIGNMENT BUTTONS */
        .align-group {
            display: flex;
            gap: 8px;
            margin-bottom: 20px;
        }

        .btn-align {
            flex: 1;
            padding: 10px;
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 6px;
            cursor: pointer;
            color: var(--text-muted);
            transition: 0.2s;
            font-weight: bold;
            font-size: 1.1rem;
        }

        .btn-align:hover {
            background: #f1f5f9;
        }

        .btn-align.active {
            background: var(--primary);
            color: #fff;
            border-color: var(--primary);
        }

        .load-box {
            background: #eff6ff;
            padding: 15px;
            border-radius: var(--radius);
            margin-bottom: 25px;
            border: 1px dashed #93c5fd;
        }

        .canvas-container {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: flex-start;
            min-height: 600px;
            background: #f1f5f9;
            border-radius: 8px;
            padding: 30px;
            overflow: auto;
            border: 2px dashed #cbd5e1;
        }

        #canvas-wrapper {
            background: #fff;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
            position: relative;
            border: 1px solid #94a3b8;
            overflow: hidden;
            background-image: radial-gradient(#cbd5e1 1px, transparent 1px);
            background-size: 10px 10px;
            margin: 0 auto;
        }

        /* DRAGGABLE ITEMS */
        .drag-item {
            position: absolute;
            cursor: grab;
            border: 1px dashed transparent;
            padding: 2px;
            user-select: none;
            font-family: monospace;
            line-height: 1.2;
            box-sizing: border-box;
            white-space: pre-wrap;
        }

        .drag-item:active {
            cursor: grabbing;
        }

        .drag-item.active {
            border-color: var(--primary);
            background: rgba(79, 70, 229, 0.1);
            z-index: 10;
            box-shadow: 0 0 0 1px var(--primary);
        }

        .section-title {
            font-size: 0.75rem;
            font-weight: 800;
            color: var(--text-muted);
            text-transform: uppercase;
            margin: 15px 0 10px 0;
            letter-spacing: 0.5px;
        }

        @media (max-width: 1024px) {
            body {
                padding-left: 0;
            }

            .builder-grid {
                grid-template-columns: 1fr;
            }

            .canvas-container {
                padding: 15px;
            }
        }

        @media (max-width: 768px) {
            .container {
                padding: 15px;
            }

            .page-header-box {
                justify-content: center;
            }
        }
    </style>
</head>

<body>

    <?php include 'admin_header.php'; ?>

    <div class="container">
        <div class="page-header-box">
            <h1 class="page-title"><i class="fas fa-print text-primary"></i> Thermal Print & Bill Builder</h1>
        </div>

        <div class="builder-grid">
            <div class="card" style="position: sticky; top: 20px;">

                <div class="load-box">
                    <label class="form-label" style="color:#1d4ed8; margin-bottom:8px;"><i class="fas fa-folder-open" style="margin-right:5px;"></i> Load Existing Template</label>
                    <div style="display:flex; gap:10px;">
                        <select id="load_t_id" class="form-input" style="margin:0; flex:1;">
                            <option value="0">-- Create New Blank Template --</option>
                            <?php foreach ($templates_list as $t): ?>
                                <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['template_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button class="btn btn-primary" style="width:auto; margin:0;" onclick="loadTemplate()"><i class="fas fa-download"></i></button>
                    </div>
                </div>

                <h3><i class="fas fa-file-invoice"></i> 1. Paper Setup</h3>
                <input type="hidden" id="t_id" value="0">
                <div class="form-group"><label class="form-label">Template Name</label><input type="text" id="t_name" class="form-input" placeholder="e.g. POS 3-Inch Bill"></div>

                <div class="form-group">
                    <label class="form-label">Document Type (Changes Tags)</label>
                    <select id="t_type" class="form-input" onchange="toggleVariables()">
                        <option value="pos_invoice">POS / Sales Bill</option>
                        <option value="grn_receipt">GRN / Purchase Receipt</option>
                        <option value="job_sticker">Job Work Sticker</option>
                    </select>
                </div>
                <div class="form-grid">
                    <div class="form-group" style="flex:1;"><label class="form-label">Width (mm)</label><input type="number" id="t_width" class="form-input" value="78" onchange="updateCanvas()"></div>
                    <div class="form-group" style="flex:1;"><label class="form-label">Height (mm)</label><input type="number" id="t_height" class="form-input" value="0" onchange="updateCanvas()"></div>
                </div>

                <h3 style="margin-top:25px;"><i class="fas fa-tags"></i> 2. Add Elements</h3>
                <div class="var-container">
                    <div class="section-title" style="margin-top:0;">Common Elements</div>
                    <button class="btn-var" onclick="addText('[TRISHE AGRO]')"><i class="fas fa-building"></i> Company Name</button>
                    <button class="btn-var" onclick="addText('Date: [DATE] Time: [TIME]')"><i class="fas fa-clock"></i> Date & Time</button>
                    <button class="btn-var" onclick="addText('--------------------------------')"><i class="fas fa-minus"></i> Separator Line</button>
                    <button class="btn-var" onclick="addText('Custom Text Here')"><i class="fas fa-edit"></i> Custom Text</button>

                    <div id="vars_pos_invoice">
                        <div class="section-title">POS Billing Tags</div>
                        <button class="btn-var" onclick="addText('Bill No: #[BILL_NO]')"><i class="fas fa-hashtag"></i> [BILL_NO]</button>
                        <button class="btn-var" onclick="addText('Customer: [CUST_NAME]')"><i class="fas fa-user"></i> [CUST_NAME]</button>
                        <button class="btn-var" onclick="addText('Ph: [CUST_PHONE]')"><i class="fas fa-phone"></i> [CUST_PHONE]</button>

                        <div class="section-title" style="color:var(--primary);">Dynamic Items Table</div>
                        <button class="btn-var" style="border-left:3px solid var(--primary);" onclick="addText('Item          Qty  Rate   Amt\n--------------------------------\n[ITEM_LIST]\n--------------------------------')"><i class="fas fa-list-ul text-primary"></i> Insert [ITEM_LIST]</button>

                        <div class="section-title">Totals</div>
                        <button class="btn-var" onclick="addText('Subtotal:    Rs. [SUBTOTAL]')"><i class="fas fa-calculator"></i> [SUBTOTAL]</button>
                        <button class="btn-var" onclick="addText('Discount:    Rs. [DISCOUNT]')"><i class="fas fa-tags"></i> [DISCOUNT]</button>
                        <button class="btn-var" onclick="addText('Grand Total: Rs. [GRAND_TOTAL]')"><i class="fas fa-rupee-sign"></i> [GRAND_TOTAL]</button>
                        <button class="btn-var" onclick="addText('Thank you! Visit Again.')"><i class="fas fa-smile"></i> Footer Message</button>
                    </div>

                    <div id="vars_grn_receipt" style="display:none;">
                        <div class="section-title">GRN Purchase Tags</div>
                        <button class="btn-var" onclick="addText('GRN No: #[GRN_NO]')"><i class="fas fa-file-invoice"></i> [GRN_NO]</button>
                        <button class="btn-var" onclick="addText('Supplier: [SUPPLIER_NAME]')"><i class="fas fa-truck"></i> [SUPPLIER_NAME]</button>
                        <button class="btn-var" onclick="addText('Vehicle: [VEHICLE_NO]')"><i class="fas fa-car"></i> [VEHICLE_NO]</button>

                        <div class="section-title" style="color:var(--primary);">Dynamic Item Details</div>
                        <button class="btn-var" style="border-left:3px solid var(--primary);"
                            onclick="addText('Item           Qty   Rate    Amt\n--------------------------------\n[ITEM_LIST]\n--------------------------------')">
                            <i class="fas fa-list-ul text-primary"></i> Insert GRN Item List
                        </button>
                    </div>

                    <div id="vars_job_sticker" style="display:none;">
                        <div class="section-title">Job Work Tags</div>
                        <button class="btn-var" onclick="addText('JOB: #[JOB_ID]')"><i class="fas fa-hashtag"></i> [JOB_ID]</button>
                        <button class="btn-var" onclick="addText('CUST: [CUSTOMER_NAME]')"><i class="fas fa-user"></i> [CUSTOMER_NAME]</button>
                        <button class="btn-var" onclick="addText('ITEM: [SEED]')"><i class="fas fa-seedling"></i> [SEED]</button>
                        <button class="btn-var" onclick="addText('WT: [WEIGHT] Kg')"><i class="fas fa-weight-hanging"></i> [WEIGHT]</button>
                        <button class="btn-var" onclick="addText('RS: [TOTAL]')"><i class="fas fa-rupee-sign"></i> [TOTAL]</button>
                    </div>
                </div>

                <h3 style="margin-top:25px;"><i class="fas fa-sliders-h"></i> 3. Edit Properties</h3>
                <label class="form-label">Text Alignment</label>
                <div class="align-group">
                    <button class="btn-align active" id="align-left" onclick="setAlign('left')"><i class="fas fa-align-left"></i></button>
                    <button class="btn-align" id="align-center" onclick="setAlign('center')"><i class="fas fa-align-center"></i></button>
                    <button class="btn-align" id="align-right" onclick="setAlign('right')"><i class="fas fa-align-right"></i></button>
                </div>

                <div class="form-grid">
                    <div class="form-group" style="flex:1;">
                        <label class="form-label">Font Size (px)</label>
                        <input type="number" id="prop_size" class="form-input" placeholder="12" onchange="updateProp('fontSize', this.value + 'px')">
                    </div>
                    <div class="form-group" style="flex:1;">
                        <label class="form-label">Font Weight</label>
                        <select id="prop_weight" class="form-input" onchange="updateProp('fontWeight', this.value)">
                            <option value="normal">Normal</option>
                            <option value="bold">Bold</option>
                            <option value="900">Black (Max)</option>
                        </select>
                    </div>
                </div>

                <div style="display:flex; gap:10px; margin-top:10px;">
                    <button class="btn btn-outline" style="flex:1; border-color:var(--danger); color:var(--danger);" onclick="deleteActive()"><i class="fas fa-trash"></i> Delete Selected</button>
                </div>

                <div style="margin-top:30px;">
                    <button class="btn btn-primary" style="background:var(--success); font-size:1.1rem; padding:16px; width:100%;" onclick="saveTemplate()"><i class="fas fa-save" style="margin-right:8px;"></i> Save Bill Template</button>
                </div>
            </div>

            <div class="card" style="padding:0; overflow:hidden;">
                <div class="card-header" style="border-bottom:1px solid var(--border); padding:20px; background:#f8fafc; display:flex; justify-content:space-between; align-items:center;">
                    <span style="font-weight: 800; font-size:1.2rem; color: var(--primary);"><i class="fas fa-receipt" style="margin-right:8px;"></i> Live Thermal Preview</span>
                    <span style="font-size: 0.85rem; color: var(--text-muted); font-weight:600; background:#e2e8f0; padding:5px 12px; border-radius:20px;"><i class="fas fa-keyboard"></i> Use Arrows to Move • Del to Remove</span>
                </div>
                <div class="canvas-container">
                    <div id="canvas-wrapper"></div>
                </div>
            </div>

        </div>
    </div>

    <script>
        const canvas = document.getElementById('canvas-wrapper');
        let activeItem = null;
        let isDragging = false;
        let startX, startY, initialX, initialY;

        // Toggle Variables based on Document Type
        function toggleVariables() {
            const type = document.getElementById('t_type').value;
            document.getElementById('vars_pos_invoice').style.display = 'none';
            document.getElementById('vars_grn_receipt').style.display = 'none';
            document.getElementById('vars_job_sticker').style.display = 'none';

            if (document.getElementById('vars_' + type)) {
                document.getElementById('vars_' + type).style.display = 'block';
            }
        }

        // Setup Canvas Size (1mm approx 3.78px)
        function updateCanvas() {
            let w = document.getElementById('t_width').value;
            let h = document.getElementById('t_height').value;
            canvas.style.width = (w * 3.78) + 'px';

            if (h == 0) {
                // Thermal Printer Continuous Roll Mode
                canvas.style.height = '700px';
                canvas.style.borderBottom = "3px dashed #ef4444";
            } else {
                // Fixed size (like sticker)
                canvas.style.height = (h * 3.78) + 'px';
                canvas.style.borderBottom = "1px solid #94a3b8";
            }
        }

        function createDOMElement(data) {
            const el = document.createElement('div');
            el.className = 'drag-item';
            el.innerText = data.text;
            el.style.left = data.left || '10px';

            // Auto arrange top if not provided (stacking)
            if (!data.top) {
                let currentItems = document.querySelectorAll('.drag-item');
                let lastTop = 10;
                if (currentItems.length > 0) {
                    let lastItem = currentItems[currentItems.length - 1];
                    lastTop = parseInt(lastItem.style.top) + parseInt(window.getComputedStyle(lastItem).height) + 5;
                }
                el.style.top = lastTop + 'px';
            } else {
                el.style.top = data.top;
            }

            el.style.fontSize = data.fontSize || '14px';
            el.style.fontWeight = data.fontWeight || 'normal';

            el.style.width = data.width || 'auto';
            el.style.textAlign = data.textAlign || 'left';

            el.ondblclick = function() {
                let newText = prompt("Edit Text (Use \\n for new line):", this.innerText);
                if (newText) this.innerText = newText.replace(/\\n/g, '\n');
            };

            el.onmousedown = dragStart;
            canvas.appendChild(el);
            setActive(el);
        }

        function addText(text) {
            createDOMElement({
                text: text
            });
        }

        function setActive(el) {
            if (activeItem) activeItem.classList.remove('active');
            activeItem = el;
            activeItem.classList.add('active');

            document.getElementById('prop_size').value = parseInt(window.getComputedStyle(el).fontSize);
            document.getElementById('prop_weight').value = window.getComputedStyle(el).fontWeight == 700 ? 'bold' : window.getComputedStyle(el).fontWeight;

            let currentAlign = el.style.textAlign || 'left';
            document.querySelectorAll('.btn-align').forEach(b => b.classList.remove('active'));
            let alignBtn = document.getElementById('align-' + currentAlign);
            if (alignBtn) alignBtn.classList.add('active');
        }

        function setAlign(type) {
            if (!activeItem) return;
            activeItem.style.textAlign = type;

            // To align center/right in absolute positioning, width must be 100% and left 0
            if (type === 'center' || type === 'right') {
                activeItem.style.width = '100%';
                activeItem.style.left = '0px';
            } else {
                activeItem.style.width = 'auto';
            }

            document.querySelectorAll('.btn-align').forEach(b => b.classList.remove('active'));
            document.getElementById('align-' + type).classList.add('active');
        }

        function updateProp(prop, value) {
            if (activeItem) activeItem.style[prop] = value;
        }

        function deleteActive() {
            if (activeItem) {
                activeItem.remove();
                activeItem = null;
            }
        }

        // MOUSE DRAG LOGIC
        function dragStart(e) {
            if (e.target.className.includes('drag-item')) {
                setActive(e.target);
                isDragging = true;
                startX = e.clientX;
                startY = e.clientY;
                initialX = activeItem.offsetLeft;
                initialY = activeItem.offsetTop;
            }
        }

        document.onmousemove = function(e) {
            if (isDragging && activeItem) {
                let dx = e.clientX - startX;
                let dy = e.clientY - startY;
                activeItem.style.top = (initialY + dy) + 'px';
                if (activeItem.style.width !== '100%') {
                    activeItem.style.left = (initialX + dx) + 'px';
                }
            }
        };

        document.onmouseup = function() {
            isDragging = false;
        };

        // KEYBOARD SHORTCUTS
        document.addEventListener('keydown', function(e) {
            if (!activeItem) return;
            // Don't trigger if typing in an input box
            if (document.activeElement.tagName === 'INPUT' || document.activeElement.tagName === 'SELECT') return;

            if (e.key === 'Delete' || e.key === 'Backspace') {
                deleteActive();
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                activeItem.style.top = (parseInt(activeItem.style.top) - 1) + 'px';
            } else if (e.key === 'ArrowDown') {
                e.preventDefault();
                activeItem.style.top = (parseInt(activeItem.style.top) + 1) + 'px';
            } else if (e.key === 'ArrowLeft' && activeItem.style.width !== '100%') {
                e.preventDefault();
                activeItem.style.left = (parseInt(activeItem.style.left) - 1) + 'px';
            } else if (e.key === 'ArrowRight' && activeItem.style.width !== '100%') {
                e.preventDefault();
                activeItem.style.left = (parseInt(activeItem.style.left) + 1) + 'px';
            }
        });

        canvas.addEventListener('mousedown', function(e) {
            if (e.target === canvas && activeItem) {
                activeItem.classList.remove('active');
                activeItem = null;
            }
        });

        // AJAX LOAD
        function loadTemplate() {
            const id = document.getElementById('load_t_id').value;
            if (id == 0) {
                document.getElementById('t_id').value = "0";
                document.getElementById('t_name').value = "";
                canvas.innerHTML = '';
                return;
            }

            const btn = event.currentTarget;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
            btn.disabled = true;

            const fd = new FormData();
            fd.append('action', 'load_template');
            fd.append('id', id);

            fetch('print_builder.php', {
                    method: 'POST',
                    body: fd
                })
                .then(r => r.json()).then(res => {
                    if (res.success) {
                        const t = res.data;
                        document.getElementById('t_id').value = t.id;
                        document.getElementById('t_name').value = t.template_name;
                        document.getElementById('t_type').value = t.doc_type;
                        document.getElementById('t_width').value = t.width_mm;
                        document.getElementById('t_height').value = t.height_mm;
                        toggleVariables();
                        updateCanvas();
                        canvas.innerHTML = '';
                        JSON.parse(t.layout_data).forEach(item => createDOMElement(item));
                    } else {
                        alert(res.error);
                    }
                    btn.innerHTML = '<i class="fas fa-download"></i>';
                    btn.disabled = false;
                }).catch(e => {
                    alert("Network Error");
                    btn.innerHTML = '<i class="fas fa-download"></i>';
                    btn.disabled = false;
                });
        }

        // AJAX SAVE
        function saveTemplate() {
            const id = document.getElementById('t_id').value;
            const name = document.getElementById('t_name').value;
            const type = document.getElementById('t_type').value;
            const w = document.getElementById('t_width').value;
            const h = document.getElementById('t_height').value;

            if (!name) return alert("Please enter a Template Name!");

            let items = [];
            document.querySelectorAll('.drag-item').forEach(el => {
                items.push({
                    text: el.innerText,
                    left: el.style.left,
                    top: el.style.top,
                    fontSize: el.style.fontSize,
                    fontWeight: el.style.fontWeight,
                    width: el.style.width,
                    textAlign: el.style.textAlign
                });
            });

            if (items.length === 0) return alert("Canvas is empty! Add elements first.");

            const btn = event.currentTarget;
            const originalText = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
            btn.disabled = true;

            let fd = new FormData();
            fd.append('action', 'save_template');
            fd.append('template_id', id);
            fd.append('template_name', name);
            fd.append('doc_type', type);
            fd.append('width_mm', w);
            fd.append('height_mm', h);
            fd.append('printer_name', '');
            fd.append('layout_data', JSON.stringify(items));

            fetch('print_builder.php', {
                    method: 'POST',
                    body: fd
                })
                .then(r => r.json()).then(res => {
                    if (res.success) {
                        alert(res.message);
                        window.location.reload();
                    } else {
                        alert("Error: " + res.error);
                        btn.innerHTML = originalText;
                        btn.disabled = false;
                    }
                }).catch(e => {
                    alert("Network Error");
                    btn.innerHTML = originalText;
                    btn.disabled = false;
                });
        }

        // Initialize UI
        toggleVariables();
        updateCanvas();
    </script>
</body>

</html>