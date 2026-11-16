import React, { useState, useEffect, useRef, useCallback } from 'react';

const audioCtx = typeof window !== 'undefined' ? new (window.AudioContext || window.webkitAudioContext)() : null;

const playSFX = (type) => {
  if (!audioCtx) return;
  if (audioCtx.state === 'suspended') audioCtx.resume();

  const osc = audioCtx.createOscillator();
  const gain = audioCtx.createGain();
  
  osc.connect(gain);
  gain.connect(audioCtx.destination);
  
  const now = audioCtx.currentTime;
  
  if (type === 'click') {
    osc.type = 'sine';
    osc.frequency.setValueAtTime(600, now);
    osc.frequency.exponentialRampToValueAtTime(300, now + 0.1);
    gain.gain.setValueAtTime(0.3, now);
    gain.gain.exponentialRampToValueAtTime(0.01, now + 0.1);
    osc.start(now);
    osc.stop(now + 0.1);
  } else if (type === 'correct') {
    osc.type = 'triangle';
    osc.frequency.setValueAtTime(400, now);
    osc.frequency.setValueAtTime(600, now + 0.1);
    osc.frequency.setValueAtTime(800, now + 0.2);
    gain.gain.setValueAtTime(0.3, now);
    gain.gain.linearRampToValueAtTime(0, now + 0.5);
    osc.start(now);
    osc.stop(now + 0.5);
  } else if (type === 'wrong') {
    osc.type = 'sawtooth';
    osc.frequency.setValueAtTime(150, now);
    osc.frequency.linearRampToValueAtTime(100, now + 0.5);
    gain.gain.setValueAtTime(0.3, now);
    gain.gain.linearRampToValueAtTime(0, now + 0.5);
    osc.start(now);
    osc.stop(now + 0.5);
  } else if (type === 'win') {
    osc.type = 'square';
    osc.frequency.setValueAtTime(440, now);
    osc.frequency.setValueAtTime(554, now + 0.2);
    osc.frequency.setValueAtTime(659, now + 0.4);
    osc.frequency.setValueAtTime(880, now + 0.6);
    gain.gain.setValueAtTime(0.2, now);
    gain.gain.linearRampToValueAtTime(0, now + 1.5);
    osc.start(now);
    osc.stop(now + 1.5);
  } else if (type === 'gameover') {
    osc.type = 'sawtooth';
    osc.frequency.setValueAtTime(300, now);
    osc.frequency.exponentialRampToValueAtTime(50, now + 1);
    gain.gain.setValueAtTime(0.3, now);
    gain.gain.linearRampToValueAtTime(0, now + 1.5);
    osc.start(now);
    osc.stop(now + 1.5);
  }
};

const speakText = (text) => {
  if (!window.speechSynthesis) return;
  window.speechSynthesis.cancel();
  const utterance = new SpeechSynthesisUtterance(text);
  utterance.lang = 'id-ID';
  utterance.rate = 1.1;
  utterance.pitch = 1.2;
  window.speechSynthesis.speak(utterance);
};

const stopSpeech = () => {
  if (window.speechSynthesis) {
    window.speechSynthesis.cancel();
  }
};

const questionBank = {
  powerpoint: [
    { story: "Alien mencuri tombol 'New Slide'!", question: "Di menu tab mana kita bisa menemukan tombol 'New Slide' (Slide Baru)?", options: ["Tab Home", "Tab Animations", "Tab Transitions", "Tanya Satpam"] },
    { story: "Presentasi terlihat membosankan. Alien tertidur!", question: "Bagaimana cara menambahkan efek pergerakan SAAT berganti halaman slide?", options: ["Transitions", "Insert > Pictures", "Animations", "Tutup laptop dan tidur"] },
    { story: "Bos Alien meminta presentasi dijalankan layar penuh sekarang juga!", question: "Tombol keyboard apa yang digunakan sebagai jalan pintas (shortcut) untuk memulai Slide Show?", options: ["F5", "F1", "Ctrl + S", "Alt + F4"] },
    { story: "Kita butuh gambar pesawat luar angkasa agar Alien senang.", question: "Menu apa yang harus diklik untuk memasukkan gambar ke dalam slide?", options: ["Insert > Pictures", "Home > Paste", "Design > Background", "Digambar pakai spidol di layar"] },
    { story: "Teks di slide terlalu kecil, Alien yang rabun jauh marah-marah!", question: "Ikon apa yang ditekan untuk memperbesar ukuran huruf (Font Size)?", options: ["Angka di sebelah nama Font", "Ikon B (Bold)", "Ikon U (Underline)", "Dilihat pakai kaca pembesar"] },
    { story: "Alien ingin bentuk bintang bersinar di tengah presentasi.", question: "Fitur apa yang digunakan untuk memasukkan bentuk dasar seperti kotak, lingkaran, atau bintang?", options: ["Insert > Shapes", "Insert > Table", "Design > Themes", "Cetak pakai tanah liat"] },
    { story: "Tulisan di judul tenggelam karena background-nya gelap.", question: "Bagaimana cara mengubah warna teks agar kontras?", options: ["Font Color (Ikon Huruf A berwarna)", "Text Highlight", "Shape Fill", "Disiram cat putih"] },
    { story: "Kita harus menduplikat slide peringatan bahaya ini dengan cepat!", question: "Kombinasi tombol keyboard apa untuk melakukan Copy dan Paste?", options: ["Ctrl+C lalu Ctrl+V", "Ctrl+Z lalu Ctrl+Y", "Ctrl+X lalu Ctrl+S", "Dipotong pakai gunting"] },
    { story: "Presentasinya sudah selesai, tapi kita lupa menyimpannya. Gawat!", question: "Menu apa yang harus dipilih untuk menyimpan file baru untuk pertama kalinya?", options: ["File > Save As", "File > Print", "Home > New", "Dimasukkan ke dalam kulkas"] },
    { story: "Alien ingin teksnya muncul berterbangan satu per satu dari bawah layar.", question: "Fitur apa yang mengatur efek masuk (masuknya) sebuah teks ke dalam slide?", options: ["Animations", "Transitions", "Slide Show", "Ditiup pakai kipas angin"] },
    { story: "Teks di slide ini terlalu mepet ke kiri. Alien suka yang rapi di tengah.", question: "Ikon perataan teks (Alignment) apa yang digunakan untuk membuat teks berada di tengah?", options: ["Center", "Align Left", "Justify", "Digeser pakai sapu"] },
    { story: "Alien ingin mencetak presentasi ini untuk dibawa ke planetnya.", question: "Kombinasi keyboard apa untuk membuka menu Print (Cetak)?", options: ["Ctrl + P", "Ctrl + C", "Ctrl + O", "Diteriakkan ke printer"] },
    { story: "Kita salah menghapus slide penting berisi peta pangkalan Alien!", question: "Kombinasi tombol apa untuk membatalkan perintah (Undo)?", options: ["Ctrl + Z", "Ctrl + Y", "Ctrl + X", "Menangis di pojokan"] },
    { story: "Latar belakang slide ini warnanya putih polos, sangat menyilaukan mata Alien.", question: "Menu apa yang digunakan untuk mengubah desain tema latar belakang?", options: ["Tab Design", "Tab Insert", "Tab Home", "Dipakaikan kacamata hitam"] },
    { story: "Alien bertanya: Apa sih nama ekstensi file standar dari Microsoft PowerPoint?", question: "Akhiran file apa yang menandakan itu adalah file PowerPoint?", options: [".pptx", ".docx", ".xlsx", ".alien"] },
    { story: "Presentasi sedang berjalan, tapi kita ingin keluar dari mode layar penuh tiba-tiba.", question: "Tombol keyboard apa yang ditekan untuk keluar dari Slide Show?", options: ["Esc (Escape)", "Enter", "Spacebar", "Cabut kabel power"] },
    { story: "Ada teks yang ingin kita miringkan agar terlihat seperti gaya tulisan Alien.", question: "Ikon apa yang digunakan untuk membuat teks menjadi miring (Italic)?", options: ["I (Italic)", "B (Bold)", "U (Underline)", "Miringkan monitornya"] },
    { story: "Kita perlu membuat bagan struktur organisasi pasukan Alien.", question: "Fitur cerdas apa di Insert yang digunakan untuk membuat diagram atau bagan dengan mudah?", options: ["SmartArt", "WordArt", "ClipArt", "Seni Rupa"] },
    { story: "Teks judul harus ditebalkan agar terlihat seram!", question: "Kombinasi tombol keyboard (shortcut) untuk efek Bold adalah?", options: ["Ctrl + B", "Ctrl + I", "Ctrl + U", "Ditekan kuat-kuat"] },
    { story: "Kita ingin menampilkan nomor halaman di setiap slide.", question: "Menu apa yang digunakan untuk menyisipkan Header & Footer atau Slide Number?", options: ["Insert", "Design", "Transitions", "Tulis tangan satu-satu"] },
    { story: "Slide ini tidak terpakai dan malah membuat bingung Alien.", question: "Bagaimana cara paling cepat menghapus slide yang sedang dipilih?", options: ["Tekan tombol Delete", "Klik Insert > Delete", "Tekan Ctrl+Z", "Lempar laptopnya"] },
    { story: "Alien ingin presentasinya berjalan otomatis tanpa diklik.", question: "Di mana kita bisa mengatur durasi perpindahan otomatis (Advance Slide)?", options: ["Menu Transitions", "Menu Home", "Menu View", "Pakai alarm jam weker"] },
    { story: "Format teks di kotak A sangat bagus, kita ingin menirunya persis ke kotak B.", question: "Alat berlambang kuas apa yang digunakan untuk menyalin format (gaya) teks?", options: ["Format Painter", "Copy Paste", "Text Box", "Kuas Cat Air"] },
    { story: "Kita ingin melihat seluruh slide dalam bentuk kotak-kotak kecil untuk menyusun ulang ceritanya.", question: "Mode tampilan (View) apa yang digunakan?", options: ["Slide Sorter", "Normal", "Reading View", "Dilihat dari sedotan"] },
    { story: "Kata 'Bumi' salah ketik menjadi 'Bumi bulat' di semua slide. Alien protes!", question: "Fitur apa yang bisa mencari kata dan menggantinya secara otomatis di seluruh slide?", options: ["Find and Replace", "Spelling", "Translate", "Hapus semua pakai tipe-x"] }
  ],
  word: [
    { story: "Kucing Oren melompat ke keyboard dan menghapus surat izin kita!", question: "Kombinasi tombol apa untuk 'Undo' (membatalkan perintah terakhir)?", options: ["Ctrl + Z", "Ctrl + C", "Ctrl + P", "Kasih makan ikan"] },
    { story: "Suratnya kembali, tapi judulnya di pinggir kiri. Kepsek suka di TENGAH.", question: "Ikon perataan teks mana untuk membuat judul ke tengah?", options: ["Center", "Align Left", "Justify", "Digeser spasi berkali-kali"] },
    { story: "Kita harus menebalkan nama kelompok agar terlihat jelas.", question: "Ikon apa yang digunakan untuk teks TEBAL?", options: ["B (Bold)", "I (Italic)", "U (Underline)", "Diwarnai spidol"] },
    { story: "Dokumen ini harus dicetak secepatnya sebelum bel masuk bunyi!", question: "Shortcut keyboard untuk mencetak (Print) dokumen adalah?", options: ["Ctrl + P", "Ctrl + C", "Ctrl + S", "Tiup layarnya"] },
    { story: "Kita perlu membuat daftar nama anggota kelompok pakai titik-titik rapi.", question: "Fitur apa yang digunakan untuk membuat daftar poin tidak berurutan?", options: ["Bullets", "Numbering", "Multilevel List", "Digambar pakai jangka"] },
    { story: "Kucing oren mengubah jenis huruf kita jadi aneh seperti cakar ayam!", question: "Di mana kita bisa memilih jenis huruf (misal: Arial, Times New Roman)?", options: ["Font", "Paragraph", "Styles", "Tanya guru seni"] },
    { story: "Paragraf ini terlalu rapat, sulit dibaca oleh guru.", question: "Fitur apa untuk mengatur jarak antar baris kalimat?", options: ["Line Spacing", "Indent", "Margins", "Ditarik pakai tali"] },
    { story: "Guru meminta ukuran kertasnya harus 'Legal' bukan 'A4'.", question: "Menu tab apa yang digunakan untuk mengubah ukuran kertas (Size)?", options: ["Page Layout / Layout", "Home", "Insert", "Dipotong pakai gunting"] },
    { story: "Kita perlu memasukkan tabel jadwal piket kelas.", question: "Menu utama apa yang diklik untuk menambahkan tabel?", options: ["Insert > Table", "Design > Table", "Home > Table", "Bikin pakai penggaris"] },
    { story: "Bagian bawah surat butuh garis bawah untuk tanda tangan Kepsek.", question: "Ikon apa yang memberi efek garis bawah pada teks?", options: ["U (Underline)", "B (Bold)", "I (Italic)", "Digaris pakai pensil"] },
    { story: "Teks sudah diketik huruf kecil semua, padahal harusnya KAPITAL semua!", question: "Fitur apa (Aa) yang mengubah besar kecilnya huruf secara otomatis?", options: ["Change Case", "Text Effects", "Clear Formatting", "Ketik ulang sambil nangis"] },
    { story: "Kucing menyenggol kabel mouse! Kita harus blok (select) semua teks pakai keyboard.", question: "Shortcut untuk memilih seluruh teks di dokumen adalah?", options: ["Ctrl + A", "Ctrl + B", "Ctrl + C", "Panggil kucingnya"] },
    { story: "Dokumen belum disimpan! Gawat kalau mati lampu.", question: "Kombinasi tombol keyboard untuk Save (Menyimpan) adalah?", options: ["Ctrl + S", "Ctrl + P", "Ctrl + V", "Diingat-ingat di kepala"] },
    { story: "Margin (batas tepi) kertasnya terlalu sempit, teksnya sampai terpotong saat dicetak.", question: "Di tab mana kita mengatur batas tepi (Margins) kertas?", options: ["Layout / Page Layout", "View", "Insert", "Lipat kertasnya"] },
    { story: "Kata 'Saya' berulang 100 kali dan harus diganti menjadi 'Kami'.", question: "Fitur apa yang paling tepat digunakan untuk mengganti kata sekaligus?", options: ["Find and Replace", "Spelling & Grammar", "Translate", "Tipe-x di layar monitor"] },
    { story: "Kita harus memindahkan paragraf ini ke halaman bawah tanpa mengetik ulang.", question: "Langkah memindahkan teks dengan benar adalah...", options: ["Cut lalu Paste", "Copy lalu Paste", "Delete lalu Paste", "Diangkat pakai tangan"] },
    { story: "Nama sekolah di pojok kanan atas salah ketik.", question: "Perataan teks apa yang membuat tulisan menempel lurus ke pinggir KANAN?", options: ["Align Right", "Align Left", "Center", "Ditiup ke kanan"] },
    { story: "Teks paragraf ini bergerigi di bagian kanan. Guru minta kanan-kirinya lurus rapi.", question: "Perataan teks apa yang membuat batas kanan dan kiri menjadi lurus rata?", options: ["Justify", "Center", "Align Right", "Disetrika biar rata"] },
    { story: "Kita butuh halaman kosong baru di bawah teks yang sedang diketik ini.", question: "Shortcut untuk menyisipkan jeda halaman (Page Break) langsung?", options: ["Ctrl + Enter", "Shift + Enter", "Alt + F4", "Enter 100 kali sampai bawah"] },
    { story: "Ada gambar logo OSIS yang terlalu besar di dokumen.", question: "Bagaimana cara mengecilkan gambar yang sudah dimasukkan?", options: ["Klik gambar, tarik titik di sudutnya", "Di-delete saja", "Ubah Font Size-nya", "Dicuci biar menyusut"] },
    { story: "Kita lupa nama ekstensi file Word agar tidak tertukar dengan PowerPoint.", question: "Apa akhiran file standar untuk dokumen Microsoft Word?", options: [".docx", ".pptx", ".xlsx", ".meow"] },
    { story: "Suratnya panjang sekali sampai 5 halaman. Kita ingin ke halaman terakhir dengan cepat.", question: "Kombinasi tombol untuk langsung menuju akhir dokumen?", options: ["Ctrl + End", "Page Down", "Panah Bawah", "Scroll pakai roda mouse setahun"] },
    { story: "Kucing menekan tombol yang membuat teks jadi miring semua!", question: "Shortcut untuk mengaktifkan efek teks miring (Italic)?", options: ["Ctrl + I", "Ctrl + B", "Ctrl + U", "Miringkan kepalanya"] },
    { story: "Kita ingin memberi warna latar kuning pada satu kata khusus agar terlihat seperti ditandai stabilo.", question: "Alat apa yang digunakan?", options: ["Text Highlight Color", "Font Color", "Format Painter", "Beli stabilo beneran"] },
    { story: "Surat ini butuh nomor urut dari 1 sampai 10 untuk nama siswa.", question: "Fitur apa yang membuat daftar bernomor secara berurutan?", options: ["Numbering", "Bullets", "Sort", "Dihitung pakai jari"] }
  ]
};

const cutsceneData = {
  powerpoint: [
    { text: "Bumi sedang diserang oleh Pasukan Alien...", emoji: "🛸", style: "animate-bounce" },
    { text: "Mereka mengancam bumi jika tidak bisa membuat presentasi yang bagus!", emoji: "😱", style: "animate-pulse" },
    { text: "Hanya kelas ini yang bisa mengalahkan mereka dengan Jurus PowerPoint!", emoji: "🦸‍♂️", style: "scale-150" }
  ],
  word: [
    { text: "Hari ini kelas kita kedatangan tamu tak diundang...", emoji: "🐈", style: "translate-x-full animate-[slide_3s_ease-in-out_forwards]" },
    { text: "Kucing Oren Jahil yang hobi menginjak keyboard saat kita mengetik!", emoji: "🐾", style: "animate-bounce" },
    { text: "Ayo selamatkan dokumen Kepala Sekolah dengan Jurus Word!", emoji: "💻", style: "scale-125" }
  ]
};

const shuffleArray = (array) => {
  const newArr = [...array];
  for (let i = newArr.length - 1; i > 0; i--) {
    const j = Math.floor(Math.random() * (i + 1));
    [newArr[i], newArr[j]] = [newArr[j], newArr[i]];
  }
  return newArr;
};

// KELAS PARTIKEL CANVAS
class Particle {
  constructor(x, y, color, speed, size, life) {
    this.x = x;
    this.y = y;
    this.color = color;
    this.vx = (Math.random() - 0.5) * speed;
    this.vy = (Math.random() - 0.5) * speed;
    this.size = size;
    this.alpha = 1;
    this.lifeDecay = life;
  }
  update() {
    this.x += this.vx;
    this.y += this.vy;
    this.alpha -= this.lifeDecay;
  }
  draw(ctx) {
    ctx.save();
    ctx.globalAlpha = Math.max(0, this.alpha);
    ctx.fillStyle = this.color;
    ctx.beginPath();
    ctx.arc(this.x, this.y, this.size, 0, Math.PI * 2);
    ctx.fill();
    ctx.restore();
  }
}

class BackgroundObj {
  constructor(w, h, type) {
    this.type = type;
    this.x = Math.random() * w;
    this.y = type === 'star' ? Math.random() * h : -50;
    this.size = type === 'star' ? Math.random() * 3 + 1 : Math.random() * 20 + 10;
    this.speed = type === 'star' ? Math.random() * 0.5 + 0.1 : Math.random() * 2 + 1;
    this.icon = type === 'star' ? '' : '🧶';
    this.alpha = Math.random() * 0.5 + 0.2;
  }
  update(h) {
    this.y += this.speed;
    if (this.y > h + 50) this.y = -50;
  }
  draw(ctx) {
    ctx.save();
    ctx.globalAlpha = this.alpha;
    if (this.type === 'star') {
      ctx.fillStyle = 'white';
      ctx.beginPath();
      ctx.arc(this.x, this.y, this.size, 0, Math.PI * 2);
      ctx.fill();
    } else {
      ctx.font = `${this.size}px Arial`;
      ctx.fillText(this.icon, this.x, this.y);
    }
    ctx.restore();
  }
}

// Komponen HUD
const HeaderHUD = ({ lives }) => (
  <div className="flex justify-between items-center bg-slate-800/80 backdrop-blur-sm p-4 border-b-4 border-slate-700 shadow-md w-full relative z-10">
    <div className="flex items-center gap-3">
      <span className="text-3xl">🛡️</span>
      <h1 className="text-2xl font-black text-white tracking-widest uppercase">Pahlawan Office</h1>
    </div>
    <div className="flex items-center gap-2">
      <span className="text-xl font-bold text-slate-300 mr-2">NYAWA:</span>
      {[1, 2, 3].map((heart) => (
        <span key={heart} className={`text-3xl transition-all duration-300 ${heart <= lives ? 'opacity-100 scale-100' : 'opacity-20 scale-50 grayscale'}`}>
          ❤️
        </span>
      ))}
    </div>
  </div>
);

export default function App() {
  const [currentScreen, setCurrentScreen] = useState('start');
  const [selectedMission, setSelectedMission] = useState(null);
  const [currentQuestionIndex, setCurrentQuestionIndex] = useState(0);
  const [lives, setLives] = useState(3);
  const [feedback, setFeedback] = useState(null);
  const [cutsceneIndex, setCutsceneIndex] = useState(0);
  const [activeQuestions, setActiveQuestions] = useState([]);
  
  // Refs untuk Canvas Engine
  const canvasRef = useRef(null);
  const spawnParticlesRef = useRef(() => {});
  const missionRef = useRef(null); // untuk dibaca oleh canvas loop

  useEffect(() => {
    missionRef.current = selectedMission;
  }, [selectedMission]);

  // CANVAS ENGINE (Game Loop)
  useEffect(() => {
    const canvas = canvasRef.current;
    if (!canvas) return;
    const ctx = canvas.getContext('2d');
    let animationId;
    let particles = [];
    let bgObjects = [];

    const resize = () => {
      canvas.width = window.innerWidth;
      canvas.height = window.innerHeight;
      // Init background
      bgObjects = [];
      for (let i = 0; i < 50; i++) {
        bgObjects.push(new BackgroundObj(canvas.width, canvas.height, missionRef.current === 'word' ? 'yarn' : 'star'));
      }
    };
    window.addEventListener('resize', resize);
    resize();

    // Fungsi Spawn Partikel yang bisa dipanggil React
    spawnParticlesRef.current = (x, y, type) => {
      if (type === 'trail') {
        particles.push(new Particle(x, y, `hsl(${Math.random() * 360}, 100%, 70%)`, 2, 3, 0.05));
      } else if (type === 'correct') {
        for (let i = 0; i < 150; i++) {
          particles.push(new Particle(x, y, `hsl(${Math.random() * 360}, 100%, 60%)`, 15, Math.random() * 5 + 2, 0.015));
        }
      } else if (type === 'wrong') {
        for (let i = 0; i < 50; i++) {
          particles.push(new Particle(x, y, 'red', 10, Math.random() * 8 + 4, 0.03));
        }
      }
    };

    const handleMouseMove = (e) => {
      spawnParticlesRef.current(e.clientX, e.clientY, 'trail');
    };
    window.addEventListener('mousemove', handleMouseMove);

    const render = () => {
      ctx.clearRect(0, 0, canvas.width, canvas.height);

      // Gambar Background Dinamis
      bgObjects.forEach(obj => {
        obj.update(canvas.height);
        obj.draw(ctx);
      });

      // Pastikan tipe objek sinkron dengan misi
      const targetType = missionRef.current === 'word' ? 'yarn' : 'star';
      if (bgObjects.length > 0 && bgObjects[0].type !== targetType) {
        bgObjects = [];
        for (let i = 0; i < 50; i++) {
          bgObjects.push(new BackgroundObj(canvas.width, canvas.height, targetType));
        }
      }

      // Gambar Partikel Efek
      for (let i = particles.length - 1; i >= 0; i--) {
        const p = particles[i];
        p.update();
        p.draw(ctx);
        if (p.alpha <= 0) {
          particles.splice(i, 1);
        }
      }

      animationId = window.requestAnimationFrame(render);
    };
    render();

    return () => {
      window.removeEventListener('resize', resize);
      window.removeEventListener('mousemove', handleMouseMove);
      window.cancelAnimationFrame(animationId);
    };
  }, []);


  useEffect(() => {
    let timer;
    if (currentScreen === 'cutscene' && selectedMission) {
      const scene = cutsceneData[selectedMission][cutsceneIndex];
      if (scene) {
        speakText(scene.text);
        timer = setTimeout(() => {
          if (cutsceneIndex < cutsceneData[selectedMission].length - 1) {
            setCutsceneIndex(prev => prev + 1);
          } else {
            setCurrentScreen('game');
          }
        }, 4000);
      }
    }
    return () => clearTimeout(timer);
  }, [currentScreen, selectedMission, cutsceneIndex]);

  useEffect(() => {
    if (currentScreen === 'game' && !feedback && activeQuestions.length > 0) {
      const q = activeQuestions[currentQuestionIndex];
      speakText(`${q.story}. ${q.question}`);
    }
  }, [currentScreen, currentQuestionIndex, activeQuestions, feedback]);

  const initGame = () => {
    playSFX('click');
    if (audioCtx && audioCtx.state === 'suspended') audioCtx.resume();
    setCurrentScreen('menu');
  };

  const startGame = (mission) => {
    playSFX('click');
    stopSpeech();
    
    const shuffledBank = shuffleArray(questionBank[mission]);
    const selectedFive = shuffledBank.slice(0, 5).map(q => {
      const optionsWithStatus = q.options.map((opt, i) => ({ text: opt, isCorrect: i === 0 }));
      return { ...q, options: shuffleArray(optionsWithStatus) };
    });

    setActiveQuestions(selectedFive);
    setSelectedMission(mission);
    setCutsceneIndex(0);
    setCurrentQuestionIndex(0);
    setLives(3);
    setFeedback(null);
    setCurrentScreen('cutscene');
  };

  const skipCutscene = () => {
    playSFX('click');
    stopSpeech();
    setCurrentScreen('game');
  };

  const handleAnswer = (isCorrect) => {
    stopSpeech();
    const cx = window.innerWidth / 2;
    const cy = window.innerHeight / 2;

    if (isCorrect) {
      playSFX('correct');
      spawnParticlesRef.current(cx, cy, 'correct'); // Canvas Firework
      speakText('Tepat sekali! Luar biasa!');
      setFeedback('correct');
      
      setTimeout(() => {
        setFeedback(null);
        if (currentQuestionIndex < activeQuestions.length - 1) {
          setCurrentQuestionIndex(prev => prev + 1);
        } else {
          playSFX('win');
          spawnParticlesRef.current(cx, cy, 'correct'); // Extra fireworks
          speakText('Misi berhasil! Kalian hebat!');
          setCurrentScreen('result');
        }
      }, 2500);
    } else {
      playSFX('wrong');
      spawnParticlesRef.current(cx, cy, 'wrong'); // Canvas Explode
      speakText('Aduh salah!');
      setFeedback('wrong');
      
      const newLives = lives - 1;
      setLives(newLives);
      setTimeout(() => {
        setFeedback(null);
        if (newLives <= 0) {
          playSFX('gameover');
          speakText('Game Over! Coba lagi ya.');
          setCurrentScreen('result');
        }
      }, 2500);
    }
  };

  return (
    <div className="w-full min-h-screen bg-slate-900 font-sans relative overflow-hidden">
      
      {/* CANVAS ENGINE LAYER (Always in background) */}
      <canvas 
        ref={canvasRef} 
        className="fixed inset-0 pointer-events-none z-0 mix-blend-screen"
      />

      {/* REACT UI LAYER */}
      <div className="relative z-10 w-full min-h-screen flex flex-col">
        
        {currentScreen === 'start' && (
          <div className="flex-1 flex flex-col items-center justify-center p-8 bg-slate-900/80 backdrop-blur-md">
            <div className="text-[8rem] animate-bounce mb-8 drop-shadow-2xl">🔊</div>
            <h1 className="text-4xl text-white font-bold mb-8 text-center max-w-2xl drop-shadow-lg">
              Aplikasi ini dilengkapi Fitur CANVAS Interaktif, Efek Suara, dan Narator otomatis!
            </h1>
            <button 
              onClick={initGame}
              className="bg-green-500 hover:bg-green-400 text-white text-3xl font-black py-6 px-12 rounded-full shadow-[0_10px_0_rgb(21,128,61)] active:shadow-[0_0px_0_rgb(21,128,61)] active:translate-y-[10px] transition-all cursor-pointer"
            >
              ▶ MULAI & AKTIFKAN SUARA
            </button>
          </div>
        )}

        {currentScreen === 'menu' && (
          <div className="flex-1 flex flex-col items-center justify-center p-8 bg-slate-900/60 overflow-y-auto">
            <div className="text-center mb-12 mt-8 animate-bounce">
              <h1 className="text-5xl md:text-7xl font-black text-white mb-4 drop-shadow-lg">
                PAHLAWAN <span className="text-yellow-400">OFFICE</span>
              </h1>
              <p className="text-lg md:text-2xl text-slate-300 bg-slate-800/90 p-4 rounded-xl border border-slate-700 max-w-3xl mx-auto shadow-lg">
                Pilih misi dan teriakkan aba-aba dari mejamu untuk menyelamatkan dunia! (5 Soal akan diacak otomatis tiap ronde)
              </p>
            </div>

            <div className="flex flex-col md:flex-row gap-8 w-full max-w-5xl justify-center mb-12">
              <button 
                onClick={() => startGame('powerpoint')}
                className="flex-1 bg-purple-600/90 hover:bg-purple-500 backdrop-blur-sm rounded-3xl p-10 transform hover:scale-105 transition-all shadow-2xl border-b-8 border-purple-800 group cursor-pointer"
              >
                <div className="text-7xl md:text-9xl mb-6 group-hover:animate-spin drop-shadow-xl">👽</div>
                <h2 className="text-2xl md:text-4xl font-bold text-white mb-2">Misi Kelas 7</h2>
                <p className="text-lg md:text-xl text-purple-200">Kalahkan Alien PowerPoint</p>
              </button>

              <button 
                onClick={() => startGame('word')}
                className="flex-1 bg-orange-500/90 hover:bg-orange-400 backdrop-blur-sm rounded-3xl p-10 transform hover:scale-105 transition-all shadow-2xl border-b-8 border-orange-700 group cursor-pointer"
              >
                <div className="text-7xl md:text-9xl mb-6 group-hover:animate-bounce drop-shadow-xl">🐈</div>
                <h2 className="text-2xl md:text-4xl font-bold text-white mb-2">Misi Kelas 8</h2>
                <p className="text-lg md:text-xl text-orange-100">Tangkap Kucing Oren Word</p>
              </button>
            </div>
          </div>
        )}

        {currentScreen === 'cutscene' && (
          <div className="flex-1 flex flex-col items-center justify-center p-8 bg-black/80 backdrop-blur-sm relative">
            <button 
              onClick={skipCutscene}
              className="absolute top-4 right-4 md:top-8 md:right-8 text-slate-300 hover:text-white border border-slate-500 px-4 py-2 rounded-full text-lg bg-black/50 cursor-pointer"
            >
              Lewati ⏭️
            </button>
            
            <div className="text-[8rem] md:text-[12rem] mb-12 transition-all duration-1000 ease-in-out drop-shadow-2xl">
              <span className={`inline-block ${cutsceneData[selectedMission][cutsceneIndex]?.style}`}>
                {cutsceneData[selectedMission][cutsceneIndex]?.emoji}
              </span>
            </div>
            <div className="bg-slate-800/90 border-4 border-yellow-500 p-6 md:p-8 rounded-3xl max-w-4xl text-center shadow-[0_0_50px_rgba(234,179,8,0.3)] animate-[fade-in_0.5s_ease-out]">
              <h2 className="text-3xl md:text-5xl font-bold text-white leading-tight">
                {cutsceneData[selectedMission][cutsceneIndex]?.text}
              </h2>
            </div>
          </div>
        )}

        {currentScreen === 'game' && (
          <div className="flex-1 flex flex-col">
            <HeaderHUD lives={lives} />
            <div className="flex-1 flex flex-col p-4 md:p-8 max-w-7xl mx-auto w-full overflow-y-auto">
              
              <div className="bg-slate-800/90 backdrop-blur-sm rounded-3xl p-6 mb-4 md:mb-8 border-2 border-slate-600 shadow-xl text-center">
                <h2 className="text-xl md:text-2xl text-yellow-400 font-bold mb-2 uppercase tracking-widest">
                  {selectedMission === 'word' ? '🐈 Kucing Oren Mengamuk 🐈' : '🛸 Serangan Alien 🛸'}
                  <span className="text-white ml-4 text-sm md:text-base">(Soal {currentQuestionIndex + 1}/5)</span>
                </h2>
                <p className="text-lg md:text-2xl text-white font-medium mb-4">
                  {activeQuestions[currentQuestionIndex]?.story}
                </p>
                <div className="bg-white/95 backdrop-blur-md rounded-2xl p-4 shadow-inner border-b-4 border-gray-300 inline-block w-full">
                  <h3 className="text-2xl md:text-4xl font-black text-slate-900 leading-tight">
                    {activeQuestions[currentQuestionIndex]?.question}
                  </h3>
                </div>
              </div>

              <div className="grid grid-cols-1 md:grid-cols-2 gap-4 flex-1 mb-8">
                {activeQuestions[currentQuestionIndex]?.options.map((opt, idx) => {
                  const colors = ['bg-red-500/90 border-red-700', 'bg-blue-500/90 border-blue-700', 'bg-yellow-500/90 border-yellow-700 text-black', 'bg-green-500/90 border-green-700'];
                  const shapes = ['🔺', '🔷', '🟡', '🟩'];
                  return (
                    <button
                      key={idx}
                      onClick={() => handleAnswer(opt.isCorrect)}
                      className={`${colors[idx]} backdrop-blur-md rounded-2xl p-4 md:p-6 text-left flex items-center gap-4 md:gap-6 transform hover:scale-[1.02] active:scale-95 transition-all shadow-xl border-b-8 group cursor-pointer`}
                    >
                      <span className="text-3xl md:text-5xl drop-shadow-md bg-white/30 p-2 md:p-4 rounded-xl flex-shrink-0">{shapes[idx]}</span>
                      <span className={`text-xl md:text-3xl font-bold ${idx === 2 ? 'text-black' : 'text-white'} group-hover:underline decoration-4 underline-offset-4`}>
                        {opt.text}
                      </span>
                    </button>
                  )
                })}
              </div>
            </div>

            {feedback && (
              <div className={`absolute inset-0 z-50 flex items-center justify-center ${feedback === 'correct' ? 'bg-green-500/40' : 'bg-red-600/40'} backdrop-blur-sm animate-in fade-in duration-200 pointer-events-none`}>
                <div className="text-center animate-bounce drop-shadow-2xl">
                  <div className="text-[10rem] md:text-[15rem] mb-4">
                    {feedback === 'correct' ? '✅' : '❌'}
                  </div>
                  <h1 className="text-6xl md:text-9xl font-black text-white uppercase">
                    {feedback === 'correct' ? 'TEPAT SEKALI!' : 'SALAH!'}
                  </h1>
                </div>
              </div>
            )}
          </div>
        )}

        {currentScreen === 'result' && (
          <div className={`flex-1 flex flex-col items-center justify-center p-8 bg-black/60 backdrop-blur-md`}>
            <div className="text-center animate-bounce mb-12">
              <div className="text-[8rem] md:text-[12rem] mb-6 drop-shadow-2xl">
                {lives > 0 ? '🏆' : '💀'}
              </div>
              <h1 className={`text-5xl md:text-8xl font-black mb-6 drop-shadow-lg ${lives > 0 ? 'text-green-400' : 'text-red-500'}`}>
                {lives > 0 ? 'MISI BERHASIL!' : 'GAME OVER'}
              </h1>
              <p className="text-xl md:text-3xl text-white/90 font-bold max-w-4xl mx-auto bg-slate-800/80 p-6 md:p-8 rounded-3xl shadow-xl">
                {lives > 0 
                  ? `Luar biasa! Kalian berhasil menyelamatkan dunia dengan sisa ${lives} Nyawa!` 
                  : "Nyawa kelas habis! Kalian gagal menyelesaikan misi. Ayo pelajari lagi dan balas dendam!"}
              </p>
            </div>
            <button 
              onClick={initGame}
              className="bg-white text-slate-900 text-2xl md:text-4xl font-black py-6 px-12 rounded-full hover:bg-slate-200 hover:scale-110 transition-all shadow-[0_8px_0_rgb(148,163,184)] active:translate-y-[8px] active:shadow-none cursor-pointer"
            >
              Main Lagi (Ganti Kelompok) 🔄
            </button>
          </div>
        )}
      </div>
    </div>
  );
}