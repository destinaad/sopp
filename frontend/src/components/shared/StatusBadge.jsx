import Badge from "@/components/ui/Badge";

/**
 * StatusBadge tahu soal bisnis SOPP: status apa -> label & warna apa.
 * Dipakai di ActivityTable, dan bisa dipakai lagi nanti di RiwayatSetoran,
 * halaman keuangan (DetailVerifikasi), dll — makanya ditaruh di shared/.
 *
 * status yang didukung: "pending" | "submitted" | "difference" | "draft"
 *   | "belum_input" | "lunas" | "belum_lunas"
 *
 * Catatan penamaan (penting, sering bikin bingung):
 *  - "belum_input" (underscore) → nilai resmi STATUS WORKFLOW sesuai kontrak
 *    API (draft | submitted | belum_input). Dipakai untuk kondisi "row-nya
 *    bahkan belum ada sama sekali" (mis. Dashboard Admin, card ringkasan
 *    Dashboard Teller).
 *  - "belum-setor" (strip) → nilai LAMA/LEGACY, dipertahankan di sini supaya
 *    tidak memecahkan konsumen lain yang mungkin masih memakainya. Untuk kode
 *    baru, pakai "belum_input", bukan ini.
 *  - "lunas" / "belum_lunas" (underscore) → nilai resmi STATUS KEUANGAN,
 *    dipakai di tempat yang isinya SUDAH PASTI ada aktivitas setoran (mis.
 *    tabel "Aktivitas Setoran Terakhir" di Dashboard Teller, tabel Laporan) —
 *    di situ "belum_input" tidak relevan sama sekali, karena kalau belum
 *    input ya tidak akan ada barisnya. Yang relevan ditampilkan justru
 *    kecocokan nominal (lunas / belum lunas), bukan progres pengisian.
 *
 * Status WORKFLOW dan status KEUANGAN adalah dua dimensi yang BERBEDA dan
 * TIDAK BOLEH dicampur di kolom yang sama — pilih salah satu sesuai konteks
 * halaman/tabelnya (lihat catatan di atas).
 */
export default function StatusBadge({ status }) {
  switch (status) {
    case "pending":
      return (
        <Badge variant="dot" bgColor="#fef3c7" dotColor="#f59e0b" textColor="#92400e">
          Belum Disubmit
        </Badge>
      );
    case "submitted":
      return (
        <Badge variant="outline" bgColor="#e6f1fb" borderColor="#185fa5" textColor="#185fa5">
          Sudah Submit
        </Badge>
      );
    case "belum-setor":
      return (
      <Badge variant="dot" bgColor="#fef3c7" dotColor="#f59e0b" textColor="#92400e">
        Belum Setor
        </Badge>
      );
    case "belum_input":
      return (
        <Badge variant="dot" bgColor="#e1e2e9" dotColor="#727782" textColor="#424751">
          Belum Input
        </Badge>
      );
    case "difference":
      return (
        <Badge variant="outline" bgColor="#ffdad6" borderColor="#ba1a1a" textColor="#93000a">
          Selisih
        </Badge>
      );
    case "lunas":
      return (
        <Badge variant="outline" bgColor="#e1f5ee" borderColor="#006c49" textColor="#006c49">
          Lunas
        </Badge>
      );
    case "belum_lunas":
      return (
        <Badge variant="outline" bgColor="#ffdad6" borderColor="#ba1a1a" textColor="#93000a">
          Belum Lunas
        </Badge>
      );
    case "draft":
    default:
      return (
        <Badge variant="outline" bgColor="#e1e2e9" borderColor="#c2c6d2" textColor="#424751">
          Draft
        </Badge>
      );
  }
}