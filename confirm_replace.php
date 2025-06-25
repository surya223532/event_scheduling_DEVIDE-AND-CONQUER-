<?php
session_start();
include("db.php");

// Validate session data
if (!isset($_SESSION['pending_event']) || !is_array($_SESSION['pending_event']) || 
    !isset($_SESSION['conflicting_events']) || !is_array($_SESSION['conflicting_events'])) {
    header("Location: index.php");
    exit;
}

// Ensure required keys exist in the pending event
$requiredKeys = ['title', 'date', 'start', 'duration', 'weight'];
foreach ($requiredKeys as $key) {
    if (!array_key_exists($key, $_SESSION['pending_event'])) {
        header("Location: index.php");
        exit;
    }
}

$newEvent = $_SESSION['pending_event'];
$conflictingEvents = $_SESSION['conflicting_events'];

// Function to convert weight to label
function labelBobot($weight) {
    if ($weight === null) return "Tidak Diketahui";
    
    switch ($weight) {
        case 4: return "Sangat Penting";
        case 3: return "Penting";
        case 2: return "Kurang Penting";
        case 1: return "Tidak Penting";
        default: return "Tidak Valid";
    }
}

// Cek apakah ada konflik dengan prioritas sama
$hasEqualPriority = false;
foreach ($conflictingEvents as $event) {
    if (($event['weight'] ?? 0) == ($newEvent['weight'] ?? 0)) {
        $hasEqualPriority = true;
        break;
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Konfirmasi Penggantian Jadwal</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 0;
            background-color: rgba(0,0,0,0.5);
        }
        
        .modal {
            display: block;
            position: fixed;
            z-index: 1;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0,0,0,0.4);
        }
        
        .modal-content {
            background-color: #fefefe;
            margin: 5% auto;
            padding: 20px;
            border: 1px solid #888;
            width: 80%;
            max-width: 800px;
            border-radius: 5px;
            box-shadow: 0 4px 8px 0 rgba(0,0,0,0.2);
            animation: modalopen 0.3s;
        }
        
        @keyframes modalopen {
            from {opacity: 0; transform: translateY(-50px);}
            to {opacity: 1; transform: translateY(0);}
        }
        
        h2 {
            color: #333;
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
            margin-top: 0;
        }
        
        .conflict-event {
            background-color: #fff3cd;
            padding: 15px;
            margin-bottom: 15px;
            border-left: 4px solid #ffc107;
            border-radius: 4px;
        }
        
        .equal-priority {
            background-color: #e7f1ff;
            padding: 15px;
            margin-bottom: 15px;
            border-left: 4px solid #0d6efd;
            border-radius: 4px;
        }
        
        .new-event {
            background-color: #d4edda;
            padding: 15px;
            margin-bottom: 20px;
            border-left: 4px solid #28a745;
            border-radius: 4px;
        }
        
        .radio-option {
            margin: 15px 0;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 4px;
        }
        
        .button-group {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 20px;
        }
        
        button {
            background-color: #007bff;
            color: white;
            border: none;
            padding: 10px 15px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
            transition: background-color 0.3s;
        }
        
        button:hover {
            background-color: #0069d9;
        }
        
        .btn-danger {
            background-color: #dc3545;
        }
        
        .btn-danger:hover {
            background-color: #c82333;
        }
        
        .close {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }
        
        .close:hover {
            color: black;
        }
        
        .error-message {
            color: #dc3545;
            font-weight: bold;
            margin: 10px 0;
        }
        
        .priority-info {
            font-weight: bold;
            margin: 10px 0;
            padding: 10px;
            border-radius: 4px;
        }
        
        .higher-priority {
            background-color: #d4edda;
            color: #155724;
        }
        
        .equal-priority-info {
            background-color: #e7f1ff;
            color: #0c5460;
        }
        
        .decision-group {
            margin-top: 20px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 4px;
        }
    </style>
</head>
<body>
    <!-- Modal Popup -->
    <div class="modal">
        <div class="modal-content">
            <span class="close" onclick="window.location.href='index.php'">&times;</span>
            <h2>Konfirmasi Penggantian Jadwal</h2>
            
            <div class="new-event">
                <h3>Jadwal Baru yang Akan Ditambahkan</h3>
                <p><strong>Judul:</strong> <?php echo htmlspecialchars($newEvent['title'] ?? 'Tidak tersedia'); ?></p>
                <p><strong>Tanggal:</strong> <?php echo htmlspecialchars($newEvent['date'] ?? 'Tidak tersedia'); ?></p>
                <p><strong>Waktu Mulai:</strong> <?php echo htmlspecialchars($newEvent['start'] ?? 'Tidak tersedia'); ?></p>
                <p><strong>Durasi:</strong> <?php echo htmlspecialchars($newEvent['duration'] ?? 'Tidak tersedia'); ?> menit</p>
                <p><strong>Prioritas:</strong> <?php echo labelBobot($newEvent['weight'] ?? null); ?></p>
            </div>
            
            <h3>Jadwal yang Akan Digantikan</h3>
            
            <?php if ($hasEqualPriority): ?>
                <div class="priority-info equal-priority-info">
                    ⚠️ Beberapa jadwal yang bertabrakan memiliki prioritas yang sama dengan jadwal baru.
                </div>
                
                <div class="decision-group">
                    <h4>Pilihan untuk jadwal dengan prioritas sama:</h4>
                    <div class="radio-option">
                        <label>
                            <input type="radio" name="global_decision" value="replace_all" checked>
                            Ganti semua jadwal yang bertabrakan dengan jadwal baru
                        </label>
                    </div>
                    <div class="radio-option">
                        <label>
                            <input type="radio" name="global_decision" value="keep_all">
                            Pertahankan semua jadwal yang ada (abaikan jadwal baru)
                        </label>
                    </div>
                    <div class="radio-option">
                        <label>
                            <input type="radio" name="global_decision" value="custom">
                            Tentukan pilihan untuk masing-masing jadwal
                        </label>
                    </div>
                </div>
            <?php else: ?>
                <div class="priority-info higher-priority">
                    ✔️ Jadwal baru memiliki prioritas lebih tinggi dari jadwal berikut:
                </div>
            <?php endif; ?>
            
            <?php if (empty($conflictingEvents)): ?>
                <div class="error-message">Tidak ada jadwal yang bertabrakan.</div>
            <?php else: ?>
                <form method="post" action="index.php" id="replaceForm">
                    <div id="conflictEventsContainer" style="<?php echo $hasEqualPriority ? 'display:none;' : ''; ?>">
                        <?php foreach ($conflictingEvents as $event): ?>
                            <?php 
                            // Ensure event has required fields
                            $eventId = $event['id'] ?? '0';
                            $eventTitle = $event['title'] ?? 'Tidak tersedia';
                            $eventStart = $event['start_time'] ?? 'Tidak tersedia';
                            $eventDuration = $event['duration'] ?? 'Tidak tersedia';
                            $eventWeight = $event['weight'] ?? null;
                            $isEqualPriority = ($eventWeight == ($newEvent['weight'] ?? 0));
                            ?>
                            
                            <div class="<?php echo $isEqualPriority ? 'equal-priority' : 'conflict-event'; ?>">
                                <p><strong>Judul:</strong> <?php echo htmlspecialchars($eventTitle); ?></p>
                                <p><strong>Tanggal:</strong> <?php echo htmlspecialchars($newEvent['date'] ?? 'Tidak tersedia'); ?></p>
                                <p><strong>Waktu Mulai:</strong> <?php echo htmlspecialchars($eventStart); ?></p>
                                <p><strong>Durasi:</strong> <?php echo htmlspecialchars($eventDuration); ?> menit</p>
                                <p><strong>Prioritas:</strong> <?php echo labelBobot($eventWeight); ?>
                                    <?php if ($isEqualPriority): ?>
                                        <span> (sama dengan jadwal baru)</span>
                                    <?php else: ?>
                                        <span> (lebih rendah dari jadwal baru)</span>
                                    <?php endif; ?>
                                </p>
                                
                                <?php if ($isEqualPriority): ?>
                                <div class="radio-option">
                                    <label>
                                        <input type="radio" name="replace_decision[<?php echo htmlspecialchars($eventId); ?>]" value="replace" <?php echo $isEqualPriority ? '' : 'checked'; ?>>
                                        Gantikan jadwal ini dengan jadwal baru
                                    </label>
                                </div>
                                <div class="radio-option">
                                    <label>
                                        <input type="radio" name="replace_decision[<?php echo htmlspecialchars($eventId); ?>]" value="keep" <?php echo $isEqualPriority ? 'checked' : ''; ?>>
                                        Pertahankan jadwal ini (jadwal baru tidak akan disimpan)
                                    </label>
                                </div>
                                <div class="radio-option">
                                    <label>
                                        <input type="radio" name="replace_decision[<?php echo htmlspecialchars($eventId); ?>]" value="adjust">
                                        Sesuaikan waktu jadwal yang ada (cari waktu lain yang tersedia)
                                    </label>
                                </div>
                                <?php else: ?>
                                <input type="hidden" name="replace_decision[<?php echo htmlspecialchars($eventId); ?>]" value="replace">
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <input type="hidden" name="confirm_replace" value="1">
                    <div class="button-group">
                        <button type="button" class="btn-danger" onclick="window.location.href='index.php'">Batal</button>
                        <button type="submit">Konfirmasi</button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Menutup modal ketika klik di luar area konten
        window.onclick = function(event) {
            var modal = document.querySelector('.modal');
            if (event.target == modal) {
                window.location.href = 'index.php';
            }
        }
        
        // Menambahkan efek ketika modal ditutup
        document.querySelector('.close').onclick = function() {
            var modal = document.querySelector('.modal');
            modal.style.animation = 'modalclose 0.3s';
            setTimeout(function() {
                window.location.href = 'index.php';
            }, 300);
        }
        
        // Handle global decision for equal priority events
        document.querySelectorAll('input[name="global_decision"]').forEach(function(radio) {
            radio.addEventListener('change', function() {
                const conflictContainer = document.getElementById('conflictEventsContainer');
                
                if (this.value === 'custom') {
                    conflictContainer.style.display = 'block';
                } else {
                    conflictContainer.style.display = 'none';
                    
                    // Set all decisions based on global choice
                    const form = document.getElementById('replaceForm');
                    const inputs = form.querySelectorAll('input[name^="replace_decision"]');
                    
                    inputs.forEach(function(input) {
                        if (this.value === 'replace_all') {
                            input.value = 'replace';
                        } else if (this.value === 'keep_all') {
                            input.value = 'keep';
                        }
                    }.bind(this));
                }
            });
        });
    </script>
</body>
</html>