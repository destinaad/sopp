export default function FilterBar({
  dari,
  sampai,
  statusKeuangan,
  statusWorkflow,
  onDariChange,
  onSampaiChange,
  onStatusKeuanganChange,
  onStatusWorkflowChange,
  onReset,
  onTerapkan,
}) {
  return (
    <div className="bg-white rounded-lg border border-[#d9d9d6] p-4 w-full">
      <div className="flex flex-wrap gap-3 items-end">
        <div className="flex flex-col gap-1 min-w-[130px]">
          <label className="text-[#424751] text-xs font-medium">Rentang Tanggal</label>
          <input
            type="date"
            value={dari}
            onChange={(e) => onDariChange(e.target.value)}
            className="border border-[#d9d9d6] rounded px-3 py-2 text-sm text-[#191c20] focus:outline-none focus:ring-2 focus:ring-[#710014]/30"
          />
        </div>

        <div className="flex flex-col gap-1 min-w-[130px]">
          <label className="text-[#424751] text-xs font-medium">Sampai Dengan</label>
          <input
            type="date"
            value={sampai}
            onChange={(e) => onSampaiChange(e.target.value)}
            className="border border-[#d9d9d6] rounded px-3 py-2 text-sm text-[#191c20] focus:outline-none focus:ring-2 focus:ring-[#710014]/30"
          />
        </div>

        <div className="flex flex-col gap-1 min-w-[150px]">
          <label className="text-[#424751] text-xs font-medium">Status Keuangan</label>
          <select
            value={statusKeuangan}
            onChange={(e) => onStatusKeuanganChange(e.target.value)}
            className="border border-[#d9d9d6] rounded px-3 py-2 text-sm text-[#191c20] bg-white focus:outline-none focus:ring-2 focus:ring-[#710014]/30"
          >
            <option value="semua">Semua Status</option>
            <option value="lunas">Lunas</option>
            <option value="belum_lunas">Belum Lunas</option>
            <option value="gagal_dimuat">Gagal Dimuat</option>
          </select>
        </div>

        <div className="flex flex-col gap-1 min-w-[150px]">
          <label className="text-[#424751] text-xs font-medium">Status Workflow</label>
          <select
            value={statusWorkflow}
            onChange={(e) => onStatusWorkflowChange(e.target.value)}
            className="border border-[#d9d9d6] rounded px-3 py-2 text-sm text-[#191c20] bg-white focus:outline-none focus:ring-2 focus:ring-[#710014]/30"
          >
            <option value="semua">Semua Workflow</option>
            <option value="draft">Draft</option>
            <option value="submitted">Sudah Submit</option>
          </select>
        </div>

        <div className="flex gap-2 items-center shrink-0 ml-auto">
          <button
            onClick={onReset}
            className="border border-[#d9d9d6] bg-white px-4 py-2 rounded text-sm text-[#424751] hover:bg-[#f7f7f6] transition-colors"
          >
            Reset
          </button>
          <button
            onClick={onTerapkan}
            className="bg-[#710014] px-4 py-2 rounded text-sm text-white font-medium hover:bg-[#5a000f] transition-colors"
          >
            Terapkan
          </button>
        </div>
      </div>
    </div>
  );
}