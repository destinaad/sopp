import { useEffect } from "react";
import { IconCheckCircleFilled } from "@/components/ui/Icon";

export default function Toast({ message, onClose, duration = 3000 }) {
  const [tampil, setTampil] = useState(false);

  useEffect(() => {
    const t = requestAnimationFrame(() => setTampil(true));
    return () => cancelAnimationFrame(t);
  }, []);

  const handleClose = () => {
    setTampil(false);
    setTimeout(onClose, 200);
  };

  useEffect(() => {
    const timer = setTimeout(onClose, duration);
    return () => clearTimeout(timer);
  }, [onClose, duration]);

  return (
    <div className="fixed top-5 right-5 z-50">
      <div className="flex items-center gap-3 bg-[#e1f5ee] border border-[#006c49]/30 rounded-lg shadow-lg px-5 py-4 max-w-sm">
        <IconCheckCircleFilled className="size-6 shrink-0" color="#006c49" />
        <span className="text-[#006c49] text-sm font-semibold flex-1">{message}</span>
        <button onClick={onClose} className="text-[#006c49]/60 hover:text-[#006c49] text-lg leading-none shrink-0" aria-label="Tutup">
          ×
        </button>
      </div>
    </div>
  );
}